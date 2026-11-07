<?php

declare(strict_types=1);

use App\Jobs\PublishProductUpdates;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Rules\FeedHeader;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

afterEach(function (): void {
    foreach ($this->generatedFeedPaths ?? [] as $generatedFeedPath) {
        if (is_file($generatedFeedPath)) {
            unlink($generatedFeedPath);
        }
    }
});

it('writes a feed with the expected header and changes the price of the given share of products', function (): void {
    $basePath = tempnam(sys_get_temp_dir(), 'feed');
    $changedPath = tempnam(sys_get_temp_dir(), 'feed');
    $this->generatedFeedPaths = [$basePath, $changedPath];

    $this->artisan('feed:generate', ['path' => $basePath, '--rows' => 200])->assertSuccessful();
    $this->artisan('feed:generate', ['path' => $changedPath, '--rows' => 200, '--changed' => 1])->assertSuccessful();

    $baseLines = file($basePath, FILE_IGNORE_NEW_LINES);
    $changedLines = file($changedPath, FILE_IGNORE_NEW_LINES);
    expect($baseLines)->toHaveCount(201)
        ->and($baseLines[0])->toBe(implode(';', FeedHeader::COLUMNS))
        ->and(array_values(array_diff($changedLines, $baseLines)))->toBe(['SCALE-0000100;Product 100;111.90;CZK;100', 'SCALE-0000200;Product 200;211.90;CZK;0']);
});

it('writes a feed the import accepts in full', function (): void {
    Storage::fake('feeds');
    Queue::fake([PublishProductUpdates::class]);
    Partner::factory()->create(['code' => 'acme']);
    $feedPath = tempnam(sys_get_temp_dir(), 'feed');
    $this->generatedFeedPaths = [$feedPath];

    $this->artisan('feed:generate', ['path' => $feedPath, '--rows' => 200, '--changed' => 1])->assertSuccessful();
    $this->artisan('feed:import', ['partner_code' => 'acme', 'path' => $feedPath])->assertSuccessful();

    $feedImport = FeedImport::sole();
    expect($feedImport->imported_row_count)->toBe(200)
        ->and($feedImport->rejected_row_count)->toBe(0);
});

it('refuses a row count or a share of changed products that is not a whole number in range, before writing anything', function (array $options): void {
    $feedPath = sys_get_temp_dir().'/feed-never-written.csv';

    $this->artisan('feed:generate', ['path' => $feedPath, ...$options])->assertFailed();

    expect(file_exists($feedPath))->toBeFalse();
})->with([
    'no rows' => [['--rows' => 0]],
    'a share above 100' => [['--changed' => 101]],
    'a negative share' => [['--changed' => -1]],
    'rows that are not a whole number' => [['--rows' => '5abc']],
    'a share that is not a whole number' => [['--changed' => '1.5']],
]);
