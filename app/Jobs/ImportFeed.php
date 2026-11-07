<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\FeedFileMissingException;
use App\Models\FeedImport;
use App\Models\Product;
use App\Models\RejectedRow;
use App\Rules\FeedHeader;
use Illuminate\Container\Attributes\Storage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Middleware\FailOnException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\LazyCollection;
use Throwable;

#[Tries(3)]
#[Backoff(10, 60)]
#[Timeout(300)]
#[DeleteWhenMissingModels]
final class ImportFeed implements ShouldQueue
{
    use Queueable;

    private const array ROW_RULES = [
        'sku' => ['required', 'string', 'max:64'],
        'name' => ['required', 'string', 'max:255'],
        // decimal(10,2) rounds a third decimal without an error, and numeric alone lets 1e3 and +129.90 through
        'price' => ['required', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,2})?$/D', 'max:99999999.99'],
        'currency' => ['required', 'string', 'regex:/^[A-Z]{3}$/D'],
        'stock' => ['required', 'integer', 'min:0', 'max:4294967295'],
    ];

    private const array ROW_MESSAGES = [
        'price.regex' => 'The price must be a number with at most two decimals, such as 129.90.',
        'currency.regex' => 'The currency must be a three-letter code in capitals, such as CZK.',
    ];

    public function __construct(public FeedImport $feedImport) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        // another attempt reads the same missing file
        return [new FailOnException([FeedFileMissingException::class])];
    }

    public function handle(#[Storage('feeds')] Filesystem $feedDisk): void
    {
        $this->feedImport->markProcessing();
        // a retry starts over: the upsert can run twice, the rejected rows would be stored twice
        $this->feedImport->rejectedRows()->delete();

        $feedStream = $feedDisk->readStream($this->feedImport->file_path);
        if ($feedStream === null) {
            throw new FeedFileMissingException(sprintf('Feed file %s was not found on the feed disk.', $this->feedImport->file_path));
        }

        try {
            $this->importRows($feedStream);
        } finally {
            fclose($feedStream);
        }
    }

    /**
     * @param  resource  $feedStream
     */
    private function importRows($feedStream): void
    {
        // a wrong header reaches here only from the artisan command, the upload refuses it; it is the partner's data, so the import fails and the job does not
        $headerMismatch = FeedHeader::describeMismatch($feedStream);
        if ($headerMismatch !== null) {
            $this->feedImport->markFailed($headerMismatch);

            return;
        }

        $totalRowCount = 0;
        $rejectedRowCount = 0;

        foreach ($this->readRows($feedStream)->chunk(Config::integer('feed.chunk_size')) as $rowChunk) {
            // keyed by SKU, so a product sent twice keeps its last row
            $validProducts = [];
            $rejectedRows = [];

            foreach ($rowChunk as $lineNumber => $untrimmedFields) {
                if ($this->readsIntoNextRow($untrimmedFields)) {
                    $this->feedImport->markFailed(sprintf('The quote opened on line %d is not closed on its row, so the rows below it were read as one field.', $lineNumber));

                    return;
                }

                $fields = array_map(trim(...), $untrimmedFields);

                if (! mb_check_encoding(implode(';', $fields), 'UTF-8')) {
                    $rejectedRows[] = ['line_number' => $lineNumber, 'message' => 'The row is not valid UTF-8.', 'row' => ['raw' => array_map(fn (string $field): string => mb_scrub($field, 'UTF-8'), $fields)]];

                    continue;
                }

                if (count($fields) !== count(FeedHeader::COLUMNS)) {
                    $rejectedRows[] = ['line_number' => $lineNumber, 'message' => sprintf('Expected %d columns, got %d.', count(FeedHeader::COLUMNS), count($fields)), 'row' => ['raw' => $fields]];

                    continue;
                }

                $row = array_combine(FeedHeader::COLUMNS, $fields);
                $rowValidator = Validator::make($row, self::ROW_RULES, self::ROW_MESSAGES);
                if ($rowValidator->fails()) {
                    $rejectedRows[] = ['line_number' => $lineNumber, 'message' => $rowValidator->errors()->first(), 'row' => $row];

                    continue;
                }

                /** @var array{sku: string, name: string, price: string, currency: string, stock: string} $validatedRow */
                $validatedRow = $rowValidator->validated();
                $validProducts[$validatedRow['sku']] = ['partner_id' => $this->feedImport->partner_id, ...$validatedRow];
            }

            $totalRowCount += $rowChunk->count();
            $rejectedRowCount += count($rejectedRows);
            $isSuperseded = DB::transaction(function () use ($validProducts, $rejectedRows, $totalRowCount, $rejectedRowCount): bool {
                // chunks of one partner commit in turn, so no newer import can write between this check and the writes below
                $this->feedImport->partner()->lockForUpdate()->sole();
                // a retry or a slow worker would otherwise write an older feed over a newer one, with a higher version
                if ($this->feedImport->isSupersededByNewerImport()) {
                    return true;
                }

                if ($validProducts !== []) {
                    $this->upsertChangedProducts($validProducts);
                }
                RejectedRow::fillAndInsert(array_map(fn (array $rejectedRow): array => ['feed_import_id' => $this->feedImport->id, ...$rejectedRow], $rejectedRows));
                // in the chunk's transaction, so a superseded or failed import shows what it wrote
                $this->feedImport->countWrittenRows($totalRowCount - $rejectedRowCount, $rejectedRowCount);

                return false;
            }, attempts: 3);
            if ($isSuperseded) {
                $this->feedImport->markSuperseded();

                return;
            }
        }

        $this->feedImport->markCompleted($totalRowCount);
    }

    /**
     * @param  array<string, array{partner_id: int, sku: string, name: string, price: string, currency: string, stock: string}>  $validProducts
     */
    private function upsertChangedProducts(array $validProducts): void
    {
        // no lock of its own: the partner's lock keeps every other import from writing these products until commit
        $storedProducts = $this->feedImport->partner->products()
            ->whereIn('sku', array_column($validProducts, 'sku'))
            ->get()
            ->keyBy('sku');
        $changedProducts = array_values(array_filter(
            $validProducts,
            fn (array $validProduct): bool => $storedProducts->get($validProduct['sku'])?->fill($validProduct)->isDirty() ?? true,
        ));
        if ($changedProducts === []) {
            return;
        }

        Product::upsert($changedProducts, ['partner_id', 'sku'], [
            'name', 'price', 'currency', 'stock',
            // a new row takes the default 1; MySQL reads the unqualified column as the existing row's value
            'version' => DB::raw('version + 1'),
        ]);

        // the jobs table is the outbox: these rows and the job announcing them commit together
        PublishProductUpdates::dispatch(
            $this->feedImport->partner,
            array_column($changedProducts, 'sku'),
            $this->feedImport->id,
        );
    }

    /**
     * A quote left open reads on into the next row: the field takes that row's separators after its line break,
     * or, closed early in the next row, leaves the row too wide. Untrimmed, since a trim can drop the line break.
     *
     * @param  list<string>  $untrimmedFields
     */
    private function readsIntoNextRow(array $untrimmedFields): bool
    {
        return array_any($untrimmedFields, fn (string $field): bool => preg_match('/\n[^;]*;/', $field) === 1
            || (str_contains($field, "\n") && count($untrimmedFields) > count(FeedHeader::COLUMNS)));
    }

    public function failed(Throwable $throwable): void
    {
        // a QueryException's message carries the whole chunk's SQL with its values; the PDOException it wraps names the cause
        $this->feedImport->markFailed(($throwable instanceof QueryException ? $throwable->getPrevious() ?? $throwable : $throwable)->getMessage());
    }

    /**
     * Keyed by the line a row starts on, so a rejected row points at the line the partner sees in the file.
     *
     * @param  resource  $feedStream
     * @return LazyCollection<int, list<string>>
     */
    private function readRows($feedStream): LazyCollection
    {
        return LazyCollection::make(function () use ($feedStream): iterable {
            $lineNumber = 2;
            while (($fields = fgetcsv($feedStream, null, ';', '"', '')) !== false) {
                $firstLineNumber = $lineNumber;
                // a quoted field may span lines
                $lineNumber += 1 + substr_count(implode('', $fields), "\n");
                if (count($fields) === 1 && trim($fields[0] ?? '') === '') {
                    continue;
                }

                yield $firstLineNumber => array_map(fn (?string $field): string => $field ?? '', $fields);
            }
        });
    }
}
