<?php

declare(strict_types=1);

use App\Jobs\ImportFeed;
use App\Jobs\PublishProductUpdates;
use App\Models\FeedImport;
use App\Models\Partner;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * One comma-separated list per queue:work command in the file.
 *
 * @return list<string>
 */
function findWorkedQueueLists(string $workerFile): array
{
    return Str::matchAll('/queue:work --queue=(\S+)/', File::get(base_path($workerFile)))->values()->all();
}

/**
 * The import job's queue, then the publish job's.
 *
 * @return list<string|null>
 */
function findRoutedQueues(): array
{
    Queue::fake();
    $partner = Partner::factory()->create();
    ImportFeed::dispatch(FeedImport::factory()->for($partner)->pending()->create());
    PublishProductUpdates::dispatch($partner, ['ACM-001'], 1);

    return [
        Queue::pushedJobs()[ImportFeed::class][0]['queue'],
        Queue::pushedJobs()[PublishProductUpdates::class][0]['queue'],
    ];
}

// a queue renamed in AppServiceProvider but not in a worker command leaves its jobs pending, with no error anywhere
it('works every routed queue in one of the worker containers', function (): void {
    $workedQueues = array_merge(...array_map(fn (string $queueList): array => explode(',', $queueList), findWorkedQueueLists('compose.yaml')));

    expect(findRoutedQueues())->each->toBeIn($workedQueues);
});

// make demo, demo-change and scale stop both containers and run one foreground worker instead
it('works every routed queue in each queue:work of the Makefile', function (): void {
    $workedQueueLists = findWorkedQueueLists('Makefile');
    expect($workedQueueLists)->not->toBeEmpty();

    foreach ($workedQueueLists as $workedQueueList) {
        expect(findRoutedQueues())->each->toBeIn(explode(',', $workedQueueList));
    }
});

// the topic keeps per-key order only while one process publishes
it('publishes from exactly one worker container, which works nothing else', function (): void {
    [$importQueue, $publishQueue] = findRoutedQueues();
    $queueListsWithPublishing = array_filter(findWorkedQueueLists('compose.yaml'), fn (string $queueList): bool => in_array($publishQueue, explode(',', $queueList), true));

    expect($publishQueue)->not->toBe($importQueue)
        ->and(array_values($queueListsWithPublishing))->toBe([$publishQueue]);
});

it('lets an import run out its timeout before the queue hands it to another worker or the container is killed', function (): void {
    $importTimeout = (new ReflectionClass(ImportFeed::class))->getAttributes(Timeout::class)[0]->newInstance()->timeout;
    $stopGracePeriod = intval(Str::match('/\n    importer:\n(?:        .*\n)*?        stop_grace_period: (\d+)s/', File::get(base_path('compose.yaml'))));

    expect(Config::integer('queue.connections.database.retry_after'))->toBeGreaterThan($importTimeout)
        ->and($stopGracePeriod)->toBeGreaterThan($importTimeout);
});

// make reset moves this group past the dropped data; a group renamed only in config would replay it
it('resets the consumer group and topic the consumer uses', function (): void {
    $makefile = File::get(base_path('Makefile'));

    expect($makefile)->toContain(sprintf('--group %s --topic %s --reset-offsets', Config::string('feed.kafka_consumer_group'), Config::string('feed.kafka_topic')));
});
