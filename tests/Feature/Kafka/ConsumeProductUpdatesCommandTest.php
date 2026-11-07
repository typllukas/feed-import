<?php

declare(strict_types=1);

use App\Enums\PriceUpdateOutcome;
use App\Jobs\PublishProductUpdates;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductPriceChange;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Jobcloud\Kafka\Message\KafkaProducerMessage;
use Jobcloud\Kafka\Producer\KafkaProducerInterface;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;

function createTestTopicClient(string $consumerGroup): KafkaConsumer
{
    $configuration = new Conf;
    $configuration->set('metadata.broker.list', Config::string('feed.kafka_brokers'));
    $configuration->set('group.id', $consumerGroup);

    return new KafkaConsumer($configuration);
}

/**
 * @return array<int, int> the next offset of every partition, keyed by partition
 */
function findTestTopicEndOffsets(): array
{
    $kafkaConsumer = createTestTopicClient('feed-import-test-watermarks');
    $topicMetadata = $kafkaConsumer->getMetadata(false, $kafkaConsumer->newTopic(KAFKA_TEST_TOPIC), 10_000)->getTopics()->current();

    $endOffsets = [];
    foreach ($topicMetadata->getPartitions() as $partitionMetadata) {
        $lowOffset = 0;
        $highOffset = 0;
        $kafkaConsumer->queryWatermarkOffsets(KAFKA_TEST_TOPIC, $partitionMetadata->getId(), $lowOffset, $highOffset, 10_000);
        $endOffsets[$partitionMetadata->getId()] = $highOffset;
    }

    return $endOffsets;
}

/**
 * @param  array<int, int>  $offsets  keyed by partition
 */
function commitConsumerGroupOffsets(string $consumerGroup, array $offsets): void
{
    $topicPartitions = [];
    foreach ($offsets as $partition => $offset) {
        $topicPartitions[] = new TopicPartition(KAFKA_TEST_TOPIC, $partition, $offset);
    }
    createTestTopicClient($consumerGroup)->commit($topicPartitions);
}

/**
 * @return array{partition: int, offset: int}|null
 */
function findFirstPositionReadByGroup(string $consumerGroup): ?array
{
    $consumer = subscribeKafkaTestConsumer($consumerGroup);
    $deadline = time() + KAFKA_TEST_DEADLINE_SECONDS;
    while (time() < $deadline) {
        $message = pollKafkaTestConsumer($consumer);
        if ($message !== null) {
            return ['partition' => $message->getPartition(), 'offset' => $message->getOffset()];
        }
    }

    return null;
}

function produceToTestTopicPartitionZero(string $messageBody): void
{
    $producer = app(KafkaProducerInterface::class);
    $producer->produce(KafkaProducerMessage::create(KAFKA_TEST_TOPIC, 0)->withKey('run-'.Str::random(8))->withBody($messageBody));
    expect($producer->flush(10_000))->toBe(RD_KAFKA_RESP_ERR_NO_ERROR);
}

beforeEach(function (): void {
    $this->consumerGroup = 'feed-import-test-'.Str::random(8);
    Config::set('feed.kafka_topic', KAFKA_TEST_TOPIC);
    Config::set('feed.kafka_consumer_group', $this->consumerGroup);
    // earlier tests and runs leave messages of other shapes on the test topic, which would stop the consumer
    $this->testTopicEndOffsets = findTestTopicEndOffsets();
    commitConsumerGroupOffsets($this->consumerGroup, $this->testTopicEndOffsets);
    $this->app->forgetInstance(KafkaProducerInterface::class);
    $this->partner = Partner::factory()->create(['code' => 'run-'.Str::lower(Str::random(8))]);
});

it('records a duplicate product update once', function (): void {
    Product::factory()->for($this->partner)->create(['sku' => 'SKU-001', 'price' => '129.90', 'currency' => 'CZK']);
    PublishProductUpdates::dispatchSync($this->partner, ['SKU-001'], 42);
    PublishProductUpdates::dispatchSync($this->partner, ['SKU-001'], 42);

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('Assigned partitions 0, 1, 2.')
        ->expectsOutputToContain($this->partner->code.':SKU-001: '.PriceUpdateOutcome::Recorded->value)
        ->expectsOutputToContain($this->partner->code.':SKU-001: '.PriceUpdateOutcome::Stale->value)
        ->assertExitCode(0);

    expect(ProductPriceChange::where('partner_code', $this->partner->code)->pluck('price')->all())->toBe(['129.90']);
})->group('kafka');

it('commits what it applied, so a restart reads nothing again and a replay after a crash before the commit is stale', function (): void {
    Product::factory()->for($this->partner)->create(['sku' => 'SKU-001', 'price' => '129.90', 'currency' => 'CZK']);
    PublishProductUpdates::dispatchSync($this->partner, ['SKU-001'], 42);

    $this->artisan('product-updates:consume', ['--max-time' => 10])
        ->expectsOutputToContain($this->partner->code.':SKU-001: '.PriceUpdateOutcome::Recorded->value)
        ->assertExitCode(0);
    $this->artisan('product-updates:consume', ['--max-time' => 10])
        ->expectsOutputToContain('Consumed 0 messages from '.KAFKA_TEST_TOPIC.'.')
        ->assertExitCode(0);

    commitConsumerGroupOffsets($this->consumerGroup, $this->testTopicEndOffsets);
    $this->artisan('product-updates:consume', ['--max-time' => 10])
        ->expectsOutputToContain($this->partner->code.':SKU-001: '.PriceUpdateOutcome::Stale->value)
        ->assertExitCode(0);

    expect(ProductPriceChange::where('partner_code', $this->partner->code)->count())->toBe(1);
})->group('kafka');

it('stops on a message it cannot read, without applying the ones behind it or committing past it', function (): void {
    produceToTestTopicPartitionZero('{not json');
    produceToTestTopicPartitionZero(json_encode([
        'product_id' => random_int(1_000_000, 9_999_999), 'partner' => $this->partner->code, 'sku' => 'SKU-001', 'version' => 1,
        'price' => '129.90', 'currency' => 'CZK', 'feed_import_id' => 1,
    ], JSON_THROW_ON_ERROR));

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain(sprintf('Partition 0 offset %d is not a product update', $this->testTopicEndOffsets[0]))
        ->assertExitCode(1);

    expect(ProductPriceChange::where('partner_code', $this->partner->code)->count())->toBe(0)
        ->and(findFirstPositionReadByGroup($this->consumerGroup))->toBe(['partition' => 0, 'offset' => $this->testTopicEndOffsets[0]]);
})->group('kafka');

it('refuses a price with more decimals than the history stores, without writing or committing it', function (): void {
    produceToTestTopicPartitionZero(json_encode([
        'product_id' => random_int(1_000_000, 9_999_999), 'partner' => $this->partner->code, 'sku' => 'SKU-001', 'version' => 1,
        'price' => '12.345', 'currency' => 'CZK', 'feed_import_id' => 1,
    ], JSON_THROW_ON_ERROR));

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain(sprintf('Partition 0 offset %d is not a product update', $this->testTopicEndOffsets[0]))
        ->assertExitCode(1);

    expect(ProductPriceChange::where('partner_code', $this->partner->code)->count())->toBe(0)
        ->and(findFirstPositionReadByGroup($this->consumerGroup))->toBe(['partition' => 0, 'offset' => $this->testTopicEndOffsets[0]]);
})->group('kafka');
