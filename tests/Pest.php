<?php

declare(strict_types=1);

use Jobcloud\Kafka\Producer\KafkaProducerInterface;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        // keeps every test off the broker unless it asks for the app's producer
        $producer = Mockery::mock(KafkaProducerInterface::class);
        $producer->shouldReceive('produce');
        $producer->shouldReceive('flush')->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);
        $this->instance(KafkaProducerInterface::class, $producer);
    })
    ->in('Feature');
