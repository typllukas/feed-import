<?php

declare(strict_types=1);

use App\Jobs\ImportFeed;
use App\Models\FeedImport;
use App\Models\Partner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('queues an import for a partner from a local csv file', function (): void {
    Storage::fake('feeds');
    Queue::fake();
    $partner = Partner::factory()->create(['code' => 'acme']);

    $this->artisan('feed:import', ['partner_code' => 'acme', 'path' => database_path('seeders/feeds/acme.csv')])
        ->expectsOutputToContain('Queued feed import #')
        ->assertSuccessful();

    $feedImport = FeedImport::sole();
    expect($feedImport->partner->is($partner))->toBeTrue();
    Storage::disk('feeds')->assertExists($feedImport->file_path);
    Queue::assertPushed(ImportFeed::class, fn (ImportFeed $job): bool => $job->feedImport->is($feedImport));
});

it('fails for an unknown partner code without storing or queueing anything', function (): void {
    Storage::fake('feeds');
    Queue::fake();

    $this->artisan('feed:import', ['partner_code' => 'nobody', 'path' => database_path('seeders/feeds/acme.csv')])
        ->expectsOutputToContain('Unknown partner code nobody.')
        ->assertFailed();

    expect(FeedImport::count())->toBe(0)
        ->and(Storage::disk('feeds')->allFiles())->toBe([]);
    Queue::assertNothingPushed();
});

it('fails for a missing file without creating an import or queueing anything', function (): void {
    Queue::fake();
    Partner::factory()->create(['code' => 'acme']);

    $this->artisan('feed:import', ['partner_code' => 'acme', 'path' => '/nowhere/feed.csv'])
        ->expectsOutputToContain('File /nowhere/feed.csv does not exist.')
        ->assertFailed();

    expect(FeedImport::count())->toBe(0);
    Queue::assertNothingPushed();
});
