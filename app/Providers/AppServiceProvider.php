<?php

declare(strict_types=1);

namespace App\Providers;

use App\Jobs\ImportFeed;
use App\Jobs\PublishProductUpdates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Jobcloud\Kafka\Callback\KafkaErrorCallback;
use Jobcloud\Kafka\Callback\KafkaProducerDeliveryReportCallback;
use Jobcloud\Kafka\Conf\KafkaConfiguration;
use Jobcloud\Kafka\Consumer\KafkaConsumerBuilder;
use Jobcloud\Kafka\Consumer\KafkaConsumerBuilderInterface;
use Jobcloud\Kafka\Message\Encoder\NullEncoder;
use Jobcloud\Kafka\Producer\KafkaProducer;
use Jobcloud\Kafka\Producer\KafkaProducerInterface;
use RdKafka\Producer as RdKafkaProducer;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            KafkaProducerInterface::class,
            function (): KafkaProducerInterface {
                // not KafkaProducerBuilder: it forces socket.timeout.ms=50, too short for acks=all on a replicated cluster
                $configuration = new KafkaConfiguration([Config::string('feed.kafka_brokers')], [], [
                    // librdkafka's own resend of a batch cannot reorder or duplicate it inside a partition; implies acks=all
                    'enable.idempotence' => 'true',
                    // the Java client's hash, so a Java producer puts the same key on the same partition
                    'partitioner' => 'murmur2_random',
                ]);
                // both run in the poll inside produce() and flush(): a failed message throws, and so does a fatal error
                $configuration->setDrMsgCb(new KafkaProducerDeliveryReportCallback);
                $configuration->setErrorCb(new KafkaErrorCallback);

                return new KafkaProducer(new RdKafkaProducer($configuration), $configuration, new NullEncoder);
            },
        );

        $this->app->bind(
            KafkaConsumerBuilderInterface::class,
            fn (): KafkaConsumerBuilderInterface => KafkaConsumerBuilder::create()
                ->withAdditionalBroker(Config::string('feed.kafka_brokers'))
                ->withConsumerGroup(Config::string('feed.kafka_consumer_group'))
                ->withSubscription(Config::string('feed.kafka_topic')),
        );
    }

    public function boot(): void
    {
        Queue::route(ImportFeed::class, queue: 'feeds');
        Queue::route(PublishProductUpdates::class, queue: 'publishing');

        // lazy loading, unfillable and missing attributes throw outside production
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
