<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Jobcloud\Kafka\Consumer\KafkaConsumerBuilder;
use Jobcloud\Kafka\Consumer\KafkaHighLevelConsumerInterface;
use Jobcloud\Kafka\Exception\KafkaConsumerEndOfPartitionException;
use Jobcloud\Kafka\Exception\KafkaConsumerTimeoutException;
use Jobcloud\Kafka\Message\KafkaConsumerMessageInterface;
use Jobcloud\Kafka\Producer\KafkaProducerInterface;
use Tests\TestCase;

const KAFKA_TEST_TOPIC = 'product-updates-test';
const KAFKA_TEST_DEADLINE_SECONDS = 30;

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        // keeps every test off the broker unless it asks for the app's producer
        $producer = Mockery::mock(KafkaProducerInterface::class);
        $producer->shouldReceive('produce');
        $producer->shouldReceive('flush')->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);
        $this->instance(KafkaProducerInterface::class, $producer);
    })
    ->in('Feature');

function subscribeKafkaTestConsumer(string $consumerGroup): KafkaHighLevelConsumerInterface
{
    $consumer = KafkaConsumerBuilder::create()
        ->withAdditionalBroker(Config::string('feed.kafka_brokers'))
        ->withConsumerGroup($consumerGroup)
        ->withSubscription(KAFKA_TEST_TOPIC)
        ->build();
    assert($consumer instanceof KafkaHighLevelConsumerInterface);
    $consumer->subscribe();

    return $consumer;
}

function pollKafkaTestConsumer(KafkaHighLevelConsumerInterface $consumer): ?KafkaConsumerMessageInterface
{
    try {
        return $consumer->consume(500);
    } catch (KafkaConsumerTimeoutException|KafkaConsumerEndOfPartitionException) {
        return null;
    }
}
