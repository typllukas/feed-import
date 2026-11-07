<?php

declare(strict_types=1);

use App\Enums\FeedImportStatus;
use App\Jobs\ImportFeed;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\RejectedRow;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->withHeader('X-Api-Token', Config::string('feed.api_token'));
});

it('stores the uploaded feed, creates a pending import and queues the job', function (): void {
    Storage::fake('feeds');
    Queue::fake();
    $partner = Partner::factory()->create(['code' => 'acme']);
    $feedFile = UploadedFile::fake()->createWithContent('acme.csv', "sku;name;price;currency;stock\nACM-001;Hammer;129.90;CZK;4\n");

    $response = $this->postJson("/api/partners/{$partner->id}/feed-imports", ['file' => $feedFile])
        ->assertAccepted()
        ->assertJsonPath('data.status', FeedImportStatus::Pending->value)
        ->assertJsonPath('data.imported_row_count', 0)
        ->assertJsonPath('data.rejected_row_count', 0);

    $feedImport = FeedImport::findOrFail($response->json('data.id'));
    Storage::disk('feeds')->assertExists($feedImport->file_path);
    Queue::assertPushedOn('feeds', ImportFeed::class, fn (ImportFeed $job): bool => $job->feedImport->is($feedImport));
});

it('rejects an upload without a file', function (): void {
    $partner = Partner::factory()->create();

    $this->postJson("/api/partners/{$partner->id}/feed-imports", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

it('rejects an upload that is not a csv file or is over 10 MB', function (UploadedFile $feedFile, string $message): void {
    $partner = Partner::factory()->create();

    $this->postJson("/api/partners/{$partner->id}/feed-imports", ['file' => $feedFile])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => $message]);
})->with([
    'a pdf' => [fn (): UploadedFile => UploadedFile::fake()->create('feed.pdf', 10, 'application/pdf'), 'The file field must be a file of type: csv, txt.'],
    'over 10 MB' => [fn (): UploadedFile => UploadedFile::fake()->create('acme.csv', 10241, 'text/csv'), 'The file field must not be greater than 10240 kilobytes.'],
]);

it('rejects an upload whose header is not the expected one, before anything is stored', function (): void {
    Storage::fake('feeds');
    Queue::fake();
    $partner = Partner::factory()->create();
    $feedFile = UploadedFile::fake()->createWithContent('acme.csv', "name;sku;price;currency;stock\nHammer;ACM-001;129.90;CZK;4\n");

    $this->postJson("/api/partners/{$partner->id}/feed-imports", ['file' => $feedFile])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file' => 'Expected the header sku;name;price;currency;stock, got name;sku;price;currency;stock.']);

    expect(FeedImport::count())->toBe(0)
        ->and(Storage::disk('feeds')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('lists only the imports of the partner in the url', function (): void {
    $partner = Partner::factory()->create();
    $feedImport = FeedImport::factory()->for($partner)->create();
    FeedImport::factory()->create();

    $this->getJson("/api/partners/{$partner->id}/feed-imports")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $feedImport->id);
});

it('lists the imports of a partner newest first, the later one first within the same second', function (): void {
    $partner = Partner::factory()->create();
    $createdAt = now()->startOfSecond();
    $earlierImport = FeedImport::factory()->for($partner)->create(['created_at' => $createdAt]);
    $laterImport = FeedImport::factory()->for($partner)->create(['created_at' => $createdAt]);
    $oldestImport = FeedImport::factory()->for($partner)->create(['created_at' => $createdAt->copy()->subDay()]);

    expect($this->getJson("/api/partners/{$partner->id}/feed-imports")->assertOk()->json('data.*.id'))
        ->toBe([$laterImport->id, $earlierImport->id, $oldestImport->id]);
});

it('shows one import', function (): void {
    $feedImport = FeedImport::factory()->create();

    $this->getJson("/api/feed-imports/{$feedImport->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $feedImport->id);
});

it('lists only the rejected rows of the import in the url, by line number', function (): void {
    $feedImport = FeedImport::factory()->create();
    RejectedRow::factory()->for($feedImport)->create(['line_number' => 9, 'row' => ['sku' => 'ACM-009']]);
    RejectedRow::factory()->for($feedImport)->create(['line_number' => 4, 'row' => ['sku' => 'ACM-004']]);
    RejectedRow::factory()->create();

    $this->getJson("/api/feed-imports/{$feedImport->id}/rejected-rows")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.line_number', 4)
        ->assertJsonPath('data.0.row.sku', 'ACM-004');
});
