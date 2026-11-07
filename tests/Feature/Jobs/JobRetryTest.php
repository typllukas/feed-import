<?php

declare(strict_types=1);

use App\Jobs\ImportFeed;
use App\Jobs\PublishProductUpdates;
use App\Models\FeedImport;
use App\Models\Partner;
use Illuminate\Support\Facades\DB;

// the sync queue of the other tests runs failed() on the first exception, so only the queued payload shows the retries
it('queues the import with three tries, a backoff of 10 and 60 seconds and a 300 second timeout', function (): void {
    config(['queue.default' => 'database']);

    ImportFeed::dispatch(FeedImport::factory()->pending()->create());

    $payload = json_decode(DB::table('jobs')->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload)->toMatchArray(['maxTries' => 3, 'backoff' => '10,60', 'timeout' => 300, 'retryUntil' => null]);
});

it('retries a publish job 75 times, every five minutes after the first two, so it outlasts a broker restart', function (): void {
    config(['queue.default' => 'database']);

    PublishProductUpdates::dispatch(Partner::factory()->create(), ['ACM-001'], 1);

    $payload = json_decode(DB::table('jobs')->sole()->payload, true, 512, JSON_THROW_ON_ERROR);
    expect($payload)->toMatchArray(['maxTries' => 75, 'backoff' => '10,60,300', 'retryUntil' => null]);
});
