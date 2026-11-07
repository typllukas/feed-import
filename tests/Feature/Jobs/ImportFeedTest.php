<?php

declare(strict_types=1);

use App\Enums\FeedImportStatus;
use App\Exceptions\FeedFileMissingException;
use App\Jobs\ImportFeed;
use App\Jobs\PublishProductUpdates;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\Product;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\QueryException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

const FEED_WITH_TWO_BAD_ROWS = <<<'CSV'
sku;name;price;currency;stock
ACM-001;Hammer 500g;129.90;CZK;4
ACM-002;Wrench set;-349.00;CZK;12
ACM-003;Nail box;29.90;CZK;300
;Screwdriver;59.90;CZK;7
ACM-005;Pliers;89.90;CZK;0
CSV;

function createPendingImport(string $csv, ?Partner $partner = null): FeedImport
{
    Storage::fake('feeds');
    Storage::disk('feeds')->put('feeds/test.csv', $csv);

    return FeedImport::factory()
        ->for($partner ?? Partner::factory()->create(['code' => 'acme']))
        ->pending()
        ->create(['file_path' => 'feeds/test.csv']);
}

it('upserts valid rows, records rejected rows and completes the import', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $feedImport->refresh();
    expect($feedImport->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->total_row_count)->toBe(5)
        ->and($feedImport->imported_row_count)->toBe(3)
        ->and($feedImport->rejected_row_count)->toBe(2)
        ->and($feedImport->finished_at)->not->toBeNull()
        ->and(Product::count())->toBe(3)
        ->and(Product::where('sku', 'ACM-001')->firstOrFail()->price)->toBe('129.90')
        ->and($feedImport->rejectedRows()->orderBy('line_number')->pluck('line_number')->all())->toBe([3, 5])
        ->and($feedImport->rejectedRows()->where('line_number', 3)->firstOrFail()->message)->toBe('The price field must be at least 0.');
});

it('is idempotent on retry: no duplicate products or rejected rows', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    $job = new ImportFeed($feedImport);

    $job->handle(Storage::disk('feeds'));
    $job->handle(Storage::disk('feeds'));

    expect(Product::count())->toBe(3)
        ->and($feedImport->refresh()->rejectedRows()->count())->toBe(2)
        ->and($feedImport->imported_row_count)->toBe(3);
});

it('updates an existing product instead of inserting a second one', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    Product::factory()->for($feedImport->partner)->create(['sku' => 'ACM-001', 'name' => 'Old hammer', 'stock' => 99]);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $hammer = Product::where('sku', 'ACM-001')->firstOrFail();
    expect(Product::where('sku', 'ACM-001')->count())->toBe(1)
        ->and($hammer->name)->toBe('Hammer 500g')
        ->and($hammer->stock)->toBe(4);
});

it('marks the import failed when the feed file is missing', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    Storage::disk('feeds')->delete('feeds/test.csv');

    expect(fn () => ImportFeed::dispatchSync($feedImport))->toThrow(FeedFileMissingException::class);

    $feedImport->refresh();
    expect($feedImport->status)->toBe(FeedImportStatus::Failed)
        ->and($feedImport->failure_reason)->toContain('feeds/test.csv');
});

it('fails a missing feed file on its first attempt instead of retrying it', function (): void {
    Config::set('queue.default', 'database');
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    Storage::disk('feeds')->delete('feeds/test.csv');
    ImportFeed::dispatch($feedImport);

    $this->artisan('queue:work', ['--once' => true, '--queue' => 'feeds']);

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and($feedImport->refresh()->status)->toBe(FeedImportStatus::Failed);
});

it('dispatches a publish job for the valid rows of each chunk', function (): void {
    Queue::fake();
    config(['feed.chunk_size' => 2]);
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $publishedSkuBatches = Queue::pushed(PublishProductUpdates::class)
        ->filter(fn (PublishProductUpdates $job): bool => $job->partner->is($feedImport->partner) && $job->feedImportId === $feedImport->id)
        ->map(fn (PublishProductUpdates $job): array => $job->skus)
        ->values()
        ->all();
    expect($publishedSkuBatches)->toBe([['ACM-001'], ['ACM-003'], ['ACM-005']]);
});

it('imports values at the limits of the products table and rejects the next ones instead of failing the import', function (): void {
    $feedImport = createPendingImport(<<<'CSV'
        sku;name;price;currency;stock
        ACM-001;Hammer 500g;129.90;CZK;4294967296
        ACM-002;Wrench set;100000000.00;CZK;12
        ACM-003;Pliers;99999999.99;CZK;4294967295
        CSV);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->imported_row_count)->toBe(1)
        ->and(Product::where('sku', 'ACM-003')->firstOrFail()->only(['price', 'stock']))->toBe(['price' => '99999999.99', 'stock' => 4294967295])
        ->and($feedImport->rejectedRows()->orderBy('line_number')->pluck('message', 'line_number')->all())->toBe([
            2 => 'The stock field must not be greater than 4294967295.',
            3 => 'The price field must not be greater than 99999999.99.',
        ]);
});

it('rejects a row the products table cannot store and imports the rest', function (string $feedLine, string $message): void {
    $feedImport = createPendingImport(str_replace('ACM-001;Hammer 500g;129.90;CZK;4', $feedLine, FEED_WITH_TWO_BAD_ROWS));

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->imported_row_count)->toBe(2)
        ->and($feedImport->rejectedRows()->where('line_number', 2)->sole()->message)->toBe($message);
})->with([
    'a SKU over 64 characters' => [str_repeat('A', 65).';Hammer 500g;129.90;CZK;4', 'The sku field must not be greater than 64 characters.'],
    'a name over 255 characters' => ['ACM-001;'.str_repeat('a', 256).';129.90;CZK;4', 'The name field must not be greater than 255 characters.'],
    'an empty name' => ['ACM-001;;129.90;CZK;4', 'The name field is required.'],
    'a negative stock' => ['ACM-001;Hammer 500g;129.90;CZK;-1', 'The stock field must be at least 0.'],
    'a fractional stock' => ['ACM-001;Hammer 500g;129.90;CZK;4.5', 'The stock field must be an integer.'],
]);

it('skips blank lines, and lines of only spaces or tabs, instead of rejecting them', function (): void {
    $feedImport = createPendingImport("sku;name;price;currency;stock\nACM-001;Hammer;129.90;CZK;4\n\nACM-002;Pliers;89.90;CZK;0\n  \n\t\n");

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->imported_row_count)->toBe(2)
        ->and($feedImport->rejected_row_count)->toBe(0);
});

it('rejects a row with the wrong number of columns and keeps it as its raw feed line', function (): void {
    $feedImport = createPendingImport("sku;name;price;currency;stock\nACM-001;Hammer\nACM-002;Pliers;89.90;CZK;0\n");

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $rejectedRow = $feedImport->refresh()->rejectedRows()->sole();
    expect($feedImport->imported_row_count)->toBe(1)
        ->and($rejectedRow->line_number)->toBe(2)
        ->and($rejectedRow->message)->toBe('Expected 5 columns, got 2.')
        ->and($rejectedRow->feed_line)->toBe('ACM-001;Hammer');
});

it('shows a row rejected by validation in the order of the feed, not of the stored JSON', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->rejectedRows()->where('line_number', 3)->sole()->feed_line)->toBe('ACM-002;Wrench set;-349.00;CZK;12');
});

it('rejects a row that is not UTF-8 and keeps it readable', function (): void {
    $feedImport = createPendingImport("sku;name;price;currency;stock\nACM-001;\xE8ern\xE9 kladivo;129.90;CZK;4\nACM-002;Pliers;89.90;CZK;0\n");

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $rejectedRow = $feedImport->refresh()->rejectedRows()->sole();
    expect($feedImport->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->imported_row_count)->toBe(1)
        ->and($rejectedRow->message)->toBe('The row is not valid UTF-8.')
        ->and($rejectedRow->feed_line)->toBe('ACM-001;?ern? kladivo;129.90;CZK;4');
});

it('records the database cause of a failed import, not the SQL of the whole chunk', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    $driverException = new PDOException('SQLSTATE[22003]: Numeric value out of range');

    (new ImportFeed($feedImport))->failed(new QueryException('mysql', 'insert into products ... '.str_repeat('x', 70_000), [], $driverException));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Failed)
        ->and($feedImport->failure_reason)->toBe('SQLSTATE[22003]: Numeric value out of range');
});

it('writes the publish job to the jobs table beside the products, not after the commit', function (): void {
    // under the sync driver from phpunit.xml the job runs inline and never reaches the jobs table
    config(['queue.default' => 'database']);
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);

    $transactionLevelsAtQueueing = [];
    Event::listen(JobQueued::class, function () use (&$transactionLevelsAtQueueing): void {
        $transactionLevelsAtQueueing[] = DB::transactionLevel();
    });

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    // level 1 is the test's own transaction; a dispatch after the commit, or with after_commit, queues at 1
    expect($transactionLevelsAtQueueing)->toBe([2])
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(Product::where('partner_id', $feedImport->partner_id)->count())->toBe(3);
});

it('neither writes nor announces a product the feed sends unchanged', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));
    Queue::fake();

    // the same values in another notation: the stored price is 129.90
    Storage::disk('feeds')->put('feeds/test.csv', str_replace('129.90', '129.9', FEED_WITH_TWO_BAD_ROWS));
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect(Product::pluck('version')->all())->toBe([1, 1, 1])
        ->and($feedImport->refresh()->imported_row_count)->toBe(3);
    Queue::assertNothingPushed();
});

it('raises the version of a changed product only and announces only that one', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));
    Queue::fake();

    Storage::disk('feeds')->put('feeds/test.csv', str_replace('Nail box;29.90', 'Nail box;31.90', FEED_WITH_TWO_BAD_ROWS));
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect(Product::where('sku', 'ACM-003')->firstOrFail()->version)->toBe(2)
        ->and(Product::where('sku', 'ACM-001')->firstOrFail()->version)->toBe(1);
    Queue::assertPushedTimes(PublishProductUpdates::class, 1);
    Queue::assertPushed(PublishProductUpdates::class, fn (PublishProductUpdates $job): bool => $job->skus === ['ACM-003']);
});

it('keeps a line break inside a quoted field in one row, numbered by its first line', function (): void {
    $feedImport = createPendingImport(<<<'CSV'
        sku;name;price;currency;stock
        ACM-001;"Hammer
        500g";129.90;CZK;4
        ;Screwdriver;59.90;CZK;7
        CSV);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $feedImport->refresh();
    expect($feedImport->imported_row_count)->toBe(1)
        ->and($feedImport->rejected_row_count)->toBe(1)
        ->and(Product::sole()->name)->toBe("Hammer\n500g")
        ->and($feedImport->rejectedRows()->sole()->line_number)->toBe(4);
});

it('reads a quote inside an unquoted field as part of the value, not as an opening quote', function (): void {
    $feedImport = createPendingImport(<<<'CSV'
        sku;name;price;currency;stock
        MON-27;Monitor 27" LG;4990.00;CZK;5
        MON-32;Monitor 32" Dell;7990.00;CZK;2
        ;Screwdriver;59.90;CZK;7
        CSV);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $feedImport->refresh();
    expect($feedImport->imported_row_count)->toBe(2)
        ->and(Product::where('sku', 'MON-27')->firstOrFail()->name)->toBe('Monitor 27" LG')
        ->and($feedImport->rejectedRows()->sole()->line_number)->toBe(4);
});

it('keeps the last row when one feed sends the same product twice', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    Storage::disk('feeds')->put('feeds/test.csv', <<<'CSV'
        sku;name;price;currency;stock
        ACM-001;Hammer 500g;99.90;CZK;4
        ACM-001;Hammer 500g;129.90;CZK;4
        NEW-001;Saw;10.00;CZK;1
        NEW-001;Saw;12.00;CZK;1
        CSV);
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect(Product::where('sku', 'ACM-001')->firstOrFail()->price)->toBe('129.90')
        ->and(Product::where('sku', 'NEW-001')->firstOrFail()->only(['price', 'version']))->toBe(['price' => '12.00', 'version' => 1])
        ->and($feedImport->refresh()->imported_row_count)->toBe(4);
});

it('trims the fields, so padding neither fails the import nor counts as a change', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));
    Queue::fake();

    Storage::disk('feeds')->put('feeds/test.csv', str_replace('ACM-001;Hammer 500g;129.90', 'ACM-001 ;Hammer 500g; 129.90 ', FEED_WITH_TWO_BAD_ROWS));
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Completed)
        ->and(Product::where('sku', 'ACM-001')->firstOrFail()->version)->toBe(1);
    Queue::assertNothingPushed();
});

it('keeps two SKUs that differ only in case as two products', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    Storage::disk('feeds')->put('feeds/test.csv', "sku;name;price;currency;stock\nacm-001;Hammer 500g;99.90;CZK;4\n");
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect(Product::whereIn('sku', ['ACM-001', 'acm-001'])->orderBy('sku')->pluck('price', 'sku')->all())
        ->toBe(['ACM-001' => '129.90', 'acm-001' => '99.90']);
});

it('rejects a price that is not plain digits with at most two decimals', function (string $price): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    Storage::disk('feeds')->put('feeds/test.csv', str_replace('ACM-001;Hammer 500g;129.90', 'ACM-001;Hammer 500g;'.$price, FEED_WITH_TWO_BAD_ROWS));
    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $feedImport->refresh();
    expect($feedImport->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->rejectedRows()->where('line_number', 2)->sole()->message)->toBe('The price must be a number with at most two decimals, such as 129.90.')
        ->and(Product::where('sku', 'ACM-001')->firstOrFail()->price)->toBe('129.90');
})->with(['5.', '1e3', '129.999', '+129.90']);

it('rejects a currency that is not three capital letters, so the consumer never receives one it refuses', function (string $currency): void {
    $feedImport = createPendingImport(str_replace('ACM-001;Hammer 500g;129.90;CZK', 'ACM-001;Hammer 500g;129.90;'.$currency, FEED_WITH_TWO_BAD_ROWS));

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->rejectedRows()->where('line_number', 2)->sole()->message)->toBe('The currency must be a three-letter code in capitals, such as CZK.')
        ->and(Product::where('sku', 'ACM-001')->exists())->toBeFalse();
})->with(['CZ', 'czk', 'CZ1']);

it('is dropped without failing when its partner was deleted before it ran', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    $feedImport->partner->delete();
    Event::fake([JobFailed::class]);

    ImportFeed::dispatchSync($feedImport);

    Event::assertNotDispatched(JobFailed::class);
});

it('fails the import without retrying when the header is not the expected one', function (): void {
    Queue::fake();
    $feedImport = createPendingImport(<<<'CSV'
        name;sku;price;currency;stock
        Hammer 500g;ACM-001;129.90;CZK;4
        CSV);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $feedImport->refresh();
    expect($feedImport->status)->toBe(FeedImportStatus::Failed)
        ->and($feedImport->failure_reason)->toBe('Expected the header sku;name;price;currency;stock, got name;sku;price;currency;stock.')
        ->and($feedImport->finished_at)->not->toBeNull()
        ->and(Product::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('accepts a header starting with a UTF-8 byte order mark', function (): void {
    $feedImport = createPendingImport("\u{FEFF}".FEED_WITH_TWO_BAD_ROWS);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->imported_row_count)->toBe(3);
});

it('looks up numeric SKUs as strings, so the read of stored products uses the partner and SKU index', function (): void {
    Queue::fake();
    $feedImport = createPendingImport("sku;name;price;currency;stock\n4006381333931;Hammer;129.90;CZK;4\n12345;Wrench;349.00;CZK;12\n");
    $storedProductReadBindings = null;
    DB::listen(function (QueryExecuted $query) use (&$storedProductReadBindings): void {
        if (str_contains($query->sql, '`sku` in')) {
            $storedProductReadBindings ??= $query->bindings;
        }
    });

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    // an integer compared with a varchar column makes MySQL scan every product of the partner
    expect(array_slice($storedProductReadBindings ?? [], 1))->toBe(['4006381333931', '12345']);
});

it('upserts with the INSERT AS alias form, since the VALUES() form is deprecated in MySQL 8', function (): void {
    Queue::fake();
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    $productUpserts = [];
    DB::listen(function (QueryExecuted $query) use (&$productUpserts): void {
        if (str_starts_with($query->sql, 'insert into `products`')) {
            $productUpserts[] = $query->sql;
        }
    });

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($productUpserts)->not->toBeEmpty()
        ->each->toContain(' as laravel_upsert_alias on duplicate key update ');
});

it('checks for a newer import under the partner lock, in the transaction that writes the chunk', function (): void {
    Queue::fake();
    $feedImport = createPendingImport("sku;name;price;currency;stock\nA-1;Hammer;129.90;CZK;4\n");
    $outerTransactionLevel = DB::transactionLevel();
    $databaseEvents = [];
    DB::listen(function (QueryExecuted $query) use (&$databaseEvents): void {
        $databaseEvents[] = ['sql' => $query->sql, 'transaction_level' => DB::transactionLevel()];
    });
    Event::listen(TransactionCommitted::class, function () use (&$databaseEvents): void {
        $databaseEvents[] = ['sql' => 'commit', 'transaction_level' => DB::transactionLevel()];
    });

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    $findFirstEvent = fn (Closure $isWanted): ?int => array_key_first(array_filter($databaseEvents, fn (array $databaseEvent): bool => $isWanted($databaseEvent['sql'])));
    $partnerLock = $findFirstEvent(fn (string $sql): bool => str_contains($sql, 'from `partners`') && str_contains($sql, 'for update'));
    $newerImportCheck = $findFirstEvent(fn (string $sql): bool => str_contains($sql, 'exists') && str_contains($sql, '`feed_imports`'));
    $productWrite = $findFirstEvent(fn (string $sql): bool => str_starts_with($sql, 'insert into `products`'));
    expect($partnerLock)->toBeInt()
        ->and($newerImportCheck)->toBeGreaterThan($partnerLock)
        ->and($productWrite)->toBeGreaterThan($newerImportCheck)
        ->and(array_column(array_slice($databaseEvents, $partnerLock, $productWrite - $partnerLock + 1), 'transaction_level'))->each->toBe($outerTransactionLevel + 1)
        ->and(array_column(array_slice($databaseEvents, $partnerLock, $productWrite - $partnerLock), 'sql'))->not->toContain('commit');
});

it('stops without writing when a newer import of the partner is running or done, so an older feed never overwrites it', function (FeedImportStatus $newerImportStatus): void {
    Queue::fake();
    $olderImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    FeedImport::factory()->for($olderImport->partner)->create(['status' => $newerImportStatus]);

    (new ImportFeed($olderImport))->handle(Storage::disk('feeds'));

    expect($olderImport->refresh()->status)->toBe(FeedImportStatus::Superseded)
        ->and($olderImport->finished_at)->not->toBeNull()
        ->and(Product::count())->toBe(0);
    Queue::assertNotPushed(PublishProductUpdates::class);
})->with([FeedImportStatus::Processing, FeedImportStatus::Completed]);

it('is not stopped by an older import of the partner', function (): void {
    $partner = Partner::factory()->create(['code' => 'acme']);
    FeedImport::factory()->for($partner)->create();
    $newerImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS, $partner);

    (new ImportFeed($newerImport))->handle(Storage::disk('feeds'));

    expect($newerImport->refresh()->status)->toBe(FeedImportStatus::Completed);
});

it('is not stopped by a newer import that has not started or did not finish', function (FeedImportStatus $newerImportStatus): void {
    $olderImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    FeedImport::factory()->for($olderImport->partner)->create(['status' => $newerImportStatus]);

    (new ImportFeed($olderImport))->handle(Storage::disk('feeds'));

    expect($olderImport->refresh()->status)->toBe(FeedImportStatus::Completed);
})->with([FeedImportStatus::Pending, FeedImportStatus::Failed, FeedImportStatus::Superseded]);

it('is not stopped by a newer import of another partner', function (): void {
    $olderImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    FeedImport::factory()->create(['status' => FeedImportStatus::Processing]);

    (new ImportFeed($olderImport))->handle(Storage::disk('feeds'));

    expect($olderImport->refresh()->status)->toBe(FeedImportStatus::Completed);
});

it('keeps the chunks it wrote and writes no more once a newer import of the partner starts', function (): void {
    Queue::fake();
    config(['feed.chunk_size' => 2]);
    $olderImport = createPendingImport("sku;name;price;currency;stock\nACM-001;Hammer;129.90;CZK;4\nACM-002;Wrench;349.00;CZK;12\nACM-003;Nail box;29.90;CZK;300\nACM-004;Pliers;89.90;CZK;0\n");
    $newerImportStarted = false;
    DB::listen(function (QueryExecuted $query) use ($olderImport, &$newerImportStarted): void {
        if (! $newerImportStarted && str_starts_with($query->sql, 'insert into `products`')) {
            $newerImportStarted = true;
            FeedImport::factory()->for($olderImport->partner)->create(['status' => FeedImportStatus::Processing]);
        }
    });

    (new ImportFeed($olderImport))->handle(Storage::disk('feeds'));

    expect($olderImport->refresh()->status)->toBe(FeedImportStatus::Superseded)
        ->and(Product::orderBy('sku')->pluck('sku')->all())->toBe(['ACM-001', 'ACM-002']);
    Queue::assertPushed(PublishProductUpdates::class, 1);
    Queue::assertPushed(PublishProductUpdates::class, fn (PublishProductUpdates $job): bool => $job->skus === ['ACM-001', 'ACM-002']);
});

it('compares a feed only with its own partner products when another partner has the same SKU', function (): void {
    Queue::fake();
    $feedImport = createPendingImport("sku;name;price;currency;stock\nACM-001;Hammer;129.90;CZK;4\n");
    $ownProduct = Product::factory()->for($feedImport->partner)->create(['sku' => 'ACM-001', 'name' => 'Hammer', 'price' => '129.90', 'currency' => 'CZK', 'stock' => 4]);
    Product::factory()->for(Partner::factory()->create(['code' => 'globex']))->create(['sku' => 'ACM-001', 'price' => '999.00']);

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($ownProduct->refresh()->version)->toBe(1);
    Queue::assertNotPushed(PublishProductUpdates::class);
});

it('completes a retry of a failed attempt and clears its failure', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    $feedImport->markFailed('Connection refused');

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->failure_reason)->toBeNull()
        ->and($feedImport->imported_row_count)->toBe(3);
});

it('fails the import on a quote not closed on its row instead of reading the rows below it into one field', function (string $feedLines): void {
    $feedImport = createPendingImport("sku;name;price;currency;stock\nACM-001;Hammer;129.90;CZK;4\n{$feedLines}");

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Failed)
        ->and($feedImport->failure_reason)->toBe('The quote opened on line 3 is not closed on its row, so the rows below it were read as one field.')
        ->and($feedImport->finished_at)->not->toBeNull()
        ->and(Product::count())->toBe(0);
})->with([
    'never closed' => "ACM-002;\"Wrench set;349.00;CZK;12\nACM-003;Nail box;29.90;CZK;300\n",
    'never closed in the last column, which leaves five fields' => "ACM-002;Wrench set;349.00;CZK;\"12\nACM-003;Nail box;29.90;CZK;300\n",
    'closed by an inch mark on the next row, which makes a valid row of the two' => "ACM-002;\"Wrench set;349.00;CZK;12\nACM-003;Monitor 27\" LG;29.90;CZK;300\n",
    'never closed over a row that is not UTF-8' => "ACM-002;\"Wrench set;349.00;CZK;12\nACM-003;Nail box \xFF;29.90;CZK;300\n",
    'left open in the last column of a feed that quotes every field' => "\"ACM-002\";\"Wrench set\";\"349.00\";\"CZK\";\"12\n\"ACM-003\";\"Nail box\";\"29.90\";\"CZK\";\"300\"\n",
    'opened on a truncated row and closed by an inch mark below' => "ACM-002;\"\nACM-003;Monitor 27\" LG;29.90;CZK;300\n",
]);

it('rejects a row whose closed quote spans lines but which lacks a column, and imports the rest', function (): void {
    $feedImport = createPendingImport("sku;name;price;currency;stock\nACM-001;\"Hammer\nhead\";129.90;CZK\nACM-002;Pliers;89.90;CZK;0\n");

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($feedImport->refresh()->status)->toBe(FeedImportStatus::Completed)
        ->and($feedImport->imported_row_count)->toBe(1)
        ->and($feedImport->rejectedRows()->sole()->message)->toBe('Expected 5 columns, got 4.');
});

it('keeps the counts of the chunks it wrote when it stops for a newer import', function (): void {
    Queue::fake();
    config(['feed.chunk_size' => 2]);
    $olderImport = createPendingImport("sku;name;price;currency;stock\nACM-001;Hammer;129.90;CZK;4\nACM-002;Wrench;-349.00;CZK;12\nACM-003;Nail box;29.90;CZK;300\nACM-004;Pliers;89.90;CZK;0\n");
    $newerImportStarted = false;
    DB::listen(function (QueryExecuted $query) use ($olderImport, &$newerImportStarted): void {
        if (! $newerImportStarted && str_starts_with($query->sql, 'insert into `products`')) {
            $newerImportStarted = true;
            FeedImport::factory()->for($olderImport->partner)->create(['status' => FeedImportStatus::Processing]);
        }
    });

    (new ImportFeed($olderImport))->handle(Storage::disk('feeds'));

    expect($olderImport->refresh()->status)->toBe(FeedImportStatus::Superseded)
        ->and($olderImport->imported_row_count)->toBe(1)
        ->and($olderImport->rejected_row_count)->toBe(1)
        ->and($olderImport->rejectedRows()->count())->toBe(1);
});

it('stores the rejected rows of a chunk in one insert', function (): void {
    $feedImport = createPendingImport(FEED_WITH_TWO_BAD_ROWS);
    $rejectedRowInserts = 0;
    DB::listen(function (QueryExecuted $query) use (&$rejectedRowInserts): void {
        if (str_starts_with($query->sql, 'insert into `rejected_rows`')) {
            $rejectedRowInserts++;
        }
    });

    (new ImportFeed($feedImport))->handle(Storage::disk('feeds'));

    expect($rejectedRowInserts)->toBe(1)
        ->and($feedImport->rejectedRows()->orderBy('line_number')->pluck('row')->all())->toEqual([
            ['sku' => 'ACM-002', 'name' => 'Wrench set', 'price' => '-349.00', 'currency' => 'CZK', 'stock' => '12'],
            ['sku' => '', 'name' => 'Screwdriver', 'price' => '59.90', 'currency' => 'CZK', 'stock' => '7'],
        ]);
});
