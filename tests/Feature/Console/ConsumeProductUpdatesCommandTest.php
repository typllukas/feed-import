<?php

declare(strict_types=1);

use App\Enums\PriceUpdateOutcome;
use App\Models\ProductPriceChange;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Jobcloud\Kafka\Consumer\KafkaConsumerBuilderInterface;
use Jobcloud\Kafka\Consumer\KafkaHighLevelConsumerInterface;
use Jobcloud\Kafka\Exception\KafkaConsumerCommitException;
use Jobcloud\Kafka\Exception\KafkaConsumerConsumeException;
use Jobcloud\Kafka\Message\KafkaConsumerMessage;
use Mockery\MockInterface;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;

const VALID_PRODUCT_UPDATE = ['product_id' => 1, 'partner' => 'acme', 'sku' => 'ACM-001', 'version' => 1, 'price' => '129.90', 'currency' => 'CZK', 'feed_import_id' => 42];

/**
 * Gives the command a consumer without a broker, and the rebalance callback it registers.
 */
function bindSubscribedConsumer(?Closure &$rebalanceCallback = null): KafkaHighLevelConsumerInterface&MockInterface
{
    $consumer = Mockery::mock(KafkaHighLevelConsumerInterface::class);
    $consumer->shouldReceive('subscribe')->once();
    $consumerBuilder = Mockery::mock(KafkaConsumerBuilderInterface::class);
    $consumerBuilder->shouldReceive('withRebalanceCallback')->once()->andReturnUsing(function (Closure $callback) use (&$rebalanceCallback, $consumerBuilder): KafkaConsumerBuilderInterface {
        $rebalanceCallback = $callback;

        return $consumerBuilder;
    });
    $consumerBuilder->shouldReceive('build')->once()->andReturn($consumer);
    app()->instance(KafkaConsumerBuilderInterface::class, $consumerBuilder);

    return $consumer;
}

/**
 * @param  array<string, mixed>  $productUpdate
 */
function createProductUpdateMessage(array $productUpdate): KafkaConsumerMessage
{
    return new KafkaConsumerMessage('product-updates', 0, 7, 0, 'acme:ACM-001', json_encode($productUpdate, JSON_THROW_ON_ERROR), null);
}

function createFatalConsumeException(): KafkaConsumerConsumeException
{
    return new KafkaConsumerConsumeException('Fatal error', RD_KAFKA_RESP_ERR__FATAL);
}

it('keeps consuming after a broker error that passes', function (): void {
    $consumer = bindSubscribedConsumer();
    $consumer->shouldReceive('consume')->once()->andThrow(new KafkaConsumerConsumeException('Broker transport failure', RD_KAFKA_RESP_ERR__TRANSPORT));
    $consumer->shouldReceive('consume')->once()->andThrow(createFatalConsumeException());

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('Broker transport failure')
        ->assertExitCode(1);
});

it('stops on a broker error it cannot recover from', function (): void {
    $consumer = bindSubscribedConsumer();
    $consumer->shouldReceive('consume')->once()->andThrow(createFatalConsumeException());

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('Fatal error')
        ->assertExitCode(1);
});

it('stops on a message that is not a product update and leaves it uncommitted', function (array $productUpdate): void {
    $consumer = bindSubscribedConsumer();
    $consumer->shouldReceive('consume')->once()->andReturn(createProductUpdateMessage($productUpdate));
    $consumer->shouldReceive('commitAsync')->never();

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain(sprintf('Partition 0 offset 7 is not a product update (payload %s)', json_encode($productUpdate, JSON_THROW_ON_ERROR)))
        ->assertExitCode(1);

    expect(ProductPriceChange::count())->toBe(0);
})->with([
    'a product id sent as a string' => [[...VALID_PRODUCT_UPDATE, 'product_id' => '1']],
    'version 0' => [[...VALID_PRODUCT_UPDATE, 'version' => 0]],
    'a partner code over 32 characters' => [[...VALID_PRODUCT_UPDATE, 'partner' => str_repeat('a', 33)]],
    'a SKU over 64 characters' => [[...VALID_PRODUCT_UPDATE, 'sku' => str_repeat('A', 65)]],
    'a two-letter currency' => [[...VALID_PRODUCT_UPDATE, 'currency' => 'CZ']],
    'no feed import' => [Arr::except(VALID_PRODUCT_UPDATE, 'feed_import_id')],
]);

it('prints an unreadable message escaped and cut to 500 characters', function (): void {
    $consumer = bindSubscribedConsumer();
    $consumer->shouldReceive('consume')->once()->andReturn(new KafkaConsumerMessage('product-updates', 0, 7, 0, 'acme:ACM-001', "</error><info>\e[2J".str_repeat('x', 600), null));
    $consumer->shouldReceive('commitAsync')->never();

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain(sprintf('(payload </error><info>\033[2J%s...)', str_repeat('x', 482)))
        ->assertExitCode(1);
});

it('prints a message key escaped', function (): void {
    $consumer = bindSubscribedConsumer();
    $consumer->shouldReceive('consume')->once()->andReturn(new KafkaConsumerMessage('product-updates', 0, 7, 0, "acme:</error>\e[2J", json_encode(VALID_PRODUCT_UPDATE, JSON_THROW_ON_ERROR), null));
    $consumer->shouldReceive('commitAsync')->once();
    $consumer->shouldReceive('consume')->once()->andThrow(createFatalConsumeException());

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('acme:</error>\033[2J: '.PriceUpdateOutcome::Recorded->value)
        ->assertExitCode(1);
});

it('prints a database error escaped, since its SQL carries the partner SKU', function (): void {
    ProductPriceChange::creating(function (ProductPriceChange $priceChange): never {
        throw new QueryException('price_history', 'insert into product_price_changes (sku) values (?)', [$priceChange->sku], new PDOException('Lock wait timeout exceeded'));
    });
    $consumer = bindSubscribedConsumer();
    $consumer->shouldReceive('consume')->once()->andReturn(createProductUpdateMessage([...VALID_PRODUCT_UPDATE, 'sku' => 'ACM</error>']));
    $consumer->shouldReceive('commitAsync')->never();

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('values (ACM</error>)')
        ->assertExitCode(1);
});

it('stops on a database error and leaves the message uncommitted', function (): void {
    $consumer = bindSubscribedConsumer();
    // past the unsigned int version column, which the message rules do not cap
    $consumer->shouldReceive('consume')->once()->andReturn(createProductUpdateMessage([...VALID_PRODUCT_UPDATE, 'version' => 4_294_967_296]));
    $consumer->shouldReceive('commitAsync')->never();

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('Partition 0 offset 7 could not be applied')
        ->assertExitCode(1);
});

it('keeps consuming when a commit fails', function (): void {
    $consumer = bindSubscribedConsumer();
    $consumer->shouldReceive('consume')->once()->andReturn(createProductUpdateMessage(VALID_PRODUCT_UPDATE));
    $consumer->shouldReceive('commitAsync')->once()->andThrow(new KafkaConsumerCommitException('Commit failed'));
    $consumer->shouldReceive('consume')->once()->andThrow(createFatalConsumeException());

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('acme:ACM-001: '.PriceUpdateOutcome::Recorded->value)
        ->expectsOutputToContain('Commit of partition 0 offset 7 failed')
        ->assertExitCode(1);
});

it('releases its partitions when the group takes them back', function (): void {
    $rebalanceCallback = null;
    $consumer = bindSubscribedConsumer($rebalanceCallback);
    $rdKafkaConsumer = Mockery::mock(KafkaConsumer::class);
    $rdKafkaConsumer->shouldReceive('assign')->once()->with(null);
    $consumer->shouldReceive('consume')->once()->andReturnUsing(function () use (&$rebalanceCallback, $rdKafkaConsumer): never {
        $rebalanceCallback($rdKafkaConsumer, RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS, [new TopicPartition('product-updates', 0)]);

        throw createFatalConsumeException();
    });

    $this->artisan('product-updates:consume', ['--max-time' => 15])
        ->expectsOutputToContain('Revoked partitions 0.')
        ->assertExitCode(1);
});

it('refuses a maximum time that is not a whole number of seconds, before it joins the group', function (string $maximumTime): void {
    $consumerBuilder = Mockery::mock(KafkaConsumerBuilderInterface::class);
    $consumerBuilder->shouldReceive('build')->never();
    app()->instance(KafkaConsumerBuilderInterface::class, $consumerBuilder);

    $this->artisan('product-updates:consume', ['--max-time' => $maximumTime])
        ->expectsOutputToContain('--max-time must be a whole number of seconds, at least 1.')
        ->assertFailed();
})->with(['soon', '0', '2.5']);
