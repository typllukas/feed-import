<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Jobcloud\Kafka\Consumer\KafkaHighLevelConsumerInterface;
use Jobcloud\Kafka\Message\KafkaProducerMessage;
use Jobcloud\Kafka\Producer\KafkaProducerInterface;
use RdKafka\TopicPartition;

/** what the Java client's default partitioner chose on the three-partition test topic (kafka-console-producer.sh, Kafka 4.1) */
const JAVA_CLIENT_PARTITIONS_BY_KEY = [
    'acme:4006381333931' => 2,
    'acme:ACM-001' => 1,
    'acme:ACM-002' => 0,
    'acme:ACM-100' => 2,
    'globex:GLX-17' => 2,
    'initech:INI-9' => 2,
];

/**
 * @return list<int>
 */
function listAssignedPartitions(KafkaHighLevelConsumerInterface $consumer): array
{
    $partitions = array_map(fn (TopicPartition $topicPartition): int => $topicPartition->getPartition(), $consumer->getAssignment());
    sort($partitions);

    return $partitions;
}

it('puts every message of one key on one partition, in the order it was produced', function (): void {
    $this->app->forgetInstance(KafkaProducerInterface::class);
    $producer = app(KafkaProducerInterface::class);
    $runPrefix = 'run-'.Str::lower(Str::random(8));
    $keys = array_map(fn (int $keyNumber): string => sprintf('%s:SKU-%02d', $runPrefix, $keyNumber), range(1, 12));
    $versions = range(1, 5);

    foreach ($versions as $version) {
        foreach ($keys as $key) {
            $producer->produce(
                KafkaProducerMessage::create(KAFKA_TEST_TOPIC, RD_KAFKA_PARTITION_UA)
                    ->withKey($key)
                    ->withBody(json_encode(['version' => $version], JSON_THROW_ON_ERROR)),
            );
        }
    }
    expect($producer->flush(10_000))->toBe(RD_KAFKA_RESP_ERR_NO_ERROR);

    $consumer = subscribeKafkaTestConsumer('feed-import-test-'.Str::random(8));
    $partitionsByKey = [];
    $versionsByKey = [];
    $usedPartitions = [];
    $receivedCount = 0;
    $deadline = time() + KAFKA_TEST_DEADLINE_SECONDS;
    while ($receivedCount < count($keys) * count($versions) && time() < $deadline) {
        $message = pollKafkaTestConsumer($consumer);
        if ($message === null || ! is_string($message->getKey()) || ! str_starts_with($message->getKey(), $runPrefix)) {
            continue;
        }
        $partitionsByKey[$message->getKey()][$message->getPartition()] = true;
        $usedPartitions[$message->getPartition()] = true;
        $versionsByKey[$message->getKey()][] = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR)['version'];
        $receivedCount++;
    }

    expect($receivedCount)->toBe(count($keys) * count($versions));
    foreach ($keys as $key) {
        expect($partitionsByKey[$key])->toHaveCount(1)
            ->and($versionsByKey[$key])->toBe($versions);
    }
    // a partitioner sending every key to one partition would pass the one-partition-per-key check
    expect(count($usedPartitions))->toBeGreaterThan(1);
})->group('kafka');

it('puts a key on the partition the Java client puts it on, so a Java producer of the same keys keeps their order', function (): void {
    $this->app->forgetInstance(KafkaProducerInterface::class);
    $producer = app(KafkaProducerInterface::class);
    $runMarker = 'run-'.Str::lower(Str::random(8));
    foreach (array_keys(JAVA_CLIENT_PARTITIONS_BY_KEY) as $key) {
        $producer->produce(KafkaProducerMessage::create(KAFKA_TEST_TOPIC, RD_KAFKA_PARTITION_UA)->withKey($key)->withBody($runMarker));
    }
    expect($producer->flush(10_000))->toBe(RD_KAFKA_RESP_ERR_NO_ERROR);

    $consumer = subscribeKafkaTestConsumer('feed-import-test-'.Str::random(8));
    $partitionsByKey = [];
    $deadline = time() + KAFKA_TEST_DEADLINE_SECONDS;
    while (count($partitionsByKey) < count(JAVA_CLIENT_PARTITIONS_BY_KEY) && time() < $deadline) {
        $message = pollKafkaTestConsumer($consumer);
        if ($message !== null && $message->getBody() === $runMarker && is_string($message->getKey())) {
            $partitionsByKey[$message->getKey()] = $message->getPartition();
        }
    }

    ksort($partitionsByKey);
    expect($partitionsByKey)->toBe(JAVA_CLIENT_PARTITIONS_BY_KEY);
})->group('kafka');

it('creates the topic with three partitions, so two consumers of one group each get a share', function (): void {
    $consumerGroup = 'feed-import-test-'.Str::random(8);
    $firstConsumer = subscribeKafkaTestConsumer($consumerGroup);
    $secondConsumer = subscribeKafkaTestConsumer($consumerGroup);

    // both members must keep polling for the group to rebalance from one member to two
    $deadline = time() + KAFKA_TEST_DEADLINE_SECONDS;
    do {
        pollKafkaTestConsumer($firstConsumer);
        pollKafkaTestConsumer($secondConsumer);
        $firstPartitions = listAssignedPartitions($firstConsumer);
        $secondPartitions = listAssignedPartitions($secondConsumer);
    } while (($firstPartitions === [] || $secondPartitions === []) && time() < $deadline);

    $allPartitions = array_merge($firstPartitions, $secondPartitions);
    sort($allPartitions);
    expect($firstPartitions)->not->toBe([])
        ->and($secondPartitions)->not->toBe([])
        ->and(array_intersect($firstPartitions, $secondPartitions))->toBe([])
        ->and($allPartitions)->toBe([0, 1, 2]);
})->group('kafka');
