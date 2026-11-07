<?php

declare(strict_types=1);

use App\Exceptions\KafkaFlushFailedException;
use App\Jobs\PublishProductUpdates;
use App\Models\Partner;
use App\Models\Product;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Jobcloud\Kafka\Exception\KafkaBrokerException;
use Jobcloud\Kafka\Exception\KafkaProducerException;
use Jobcloud\Kafka\Message\KafkaProducerMessageInterface;
use Jobcloud\Kafka\Producer\KafkaProducerInterface;

function createAcmeWithTwoProducts(): Partner
{
    $partner = Partner::factory()->create(['code' => 'acme']);
    Product::factory()->for($partner)->create(['sku' => 'ACM-001', 'name' => 'Hammer 500g', 'price' => '129.90', 'currency' => 'CZK', 'stock' => 4]);
    Product::factory()->for($partner)->create(['sku' => 'ACM-003', 'name' => 'Nail box', 'price' => '29.90', 'currency' => 'CZK', 'stock' => 300]);

    return $partner;
}

it('publishes the current state of every listed product and flushes once', function (): void {
    $partner = createAcmeWithTwoProducts();
    $publishedPayloads = [];

    $producer = Mockery::mock(KafkaProducerInterface::class);
    $producer->shouldReceive('produce')->twice()
        ->with(Mockery::on(function (KafkaProducerMessageInterface $message) use (&$publishedPayloads): bool {
            $publishedPayloads[$message->getKey()] = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);

            return true;
        }));
    $producer->shouldReceive('flush')->once()->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

    (new PublishProductUpdates($partner, ['ACM-001', 'ACM-003'], 42))->handle($producer);

    $hammer = $partner->products()->where('sku', 'ACM-001')->sole();
    expect($publishedPayloads)->toHaveKeys(['acme:ACM-001', 'acme:ACM-003'])
        ->and($publishedPayloads['acme:ACM-001']['price'])->toBe('129.90')
        ->and($publishedPayloads['acme:ACM-001']['stock'])->toBe(4)
        ->and($publishedPayloads['acme:ACM-001']['version'])->toBe($hammer->version)
        ->and($publishedPayloads['acme:ACM-001']['product_id'])->toBe($hammer->id)
        ->and($publishedPayloads['acme:ACM-001']['feed_import_id'])->toBe(42);
});

it('fails the job when the flush does not drain', function (): void {
    $partner = createAcmeWithTwoProducts();

    $producer = Mockery::mock(KafkaProducerInterface::class);
    $producer->shouldReceive('produce')->twice();
    $producer->shouldReceive('flush')->once()->andReturn(RD_KAFKA_RESP_ERR__TIMED_OUT);

    expect(fn () => (new PublishProductUpdates($partner, ['ACM-001', 'ACM-003'], 42))->handle($producer))
        ->toThrow(KafkaFlushFailedException::class);
});

it('publishes nothing when the listed products are gone', function (): void {
    $partner = Partner::factory()->create(['code' => 'acme']);

    $producer = Mockery::mock(KafkaProducerInterface::class);
    $producer->shouldReceive('produce')->never();
    $producer->shouldReceive('flush')->once()->andReturn(RD_KAFKA_RESP_ERR_NO_ERROR);

    (new PublishProductUpdates($partner, ['ACM-404'], 42))->handle($producer);
});

it('drops the shared producer after a fatal error, so the retry builds a fresh one', function (): void {
    $partner = createAcmeWithTwoProducts();
    // a fresh mock instead of the app's binding, which would connect to the broker
    $this->app->singleton(KafkaProducerInterface::class, fn (): KafkaProducerInterface => Mockery::mock(KafkaProducerInterface::class));

    // the library's error callback throws this from the poll inside produce() or flush()
    $producer = Mockery::mock(KafkaProducerInterface::class);
    $producer->shouldReceive('produce')->once()->andThrow(new KafkaBrokerException('Fatal idempotent producer error', RD_KAFKA_RESP_ERR__FATAL));
    $this->instance(KafkaProducerInterface::class, $producer);

    expect(fn () => (new PublishProductUpdates($partner, ['ACM-001', 'ACM-003'], 42))->handle($producer))
        ->toThrow(KafkaBrokerException::class)
        ->and(app(KafkaProducerInterface::class))->toBeInstanceOf(KafkaProducerInterface::class)->not->toBe($producer);
});

it('fails the job and drops the shared producer when the broker refuses a message', function (): void {
    $partner = createAcmeWithTwoProducts();
    $this->app->singleton(KafkaProducerInterface::class, fn (): KafkaProducerInterface => Mockery::mock(KafkaProducerInterface::class));

    // the delivery report callback throws this from the poll inside flush()
    $producer = Mockery::mock(KafkaProducerInterface::class);
    $producer->shouldReceive('produce')->twice();
    $producer->shouldReceive('flush')->once()->andThrow(new KafkaProducerException('Broker: Message size too large', RD_KAFKA_RESP_ERR_MSG_SIZE_TOO_LARGE));
    $this->instance(KafkaProducerInterface::class, $producer);

    expect(fn () => (new PublishProductUpdates($partner, ['ACM-001', 'ACM-003'], 42))->handle($producer))
        ->toThrow(KafkaProducerException::class)
        ->and(app(KafkaProducerInterface::class))->not->toBe($producer);
});

it('is dropped without failing when its partner was deleted before it ran', function (): void {
    $partner = createAcmeWithTwoProducts();
    $partner->delete();
    Event::fake([JobFailed::class]);

    PublishProductUpdates::dispatchSync($partner, ['ACM-001'], 42);

    Event::assertNotDispatched(JobFailed::class);
});
