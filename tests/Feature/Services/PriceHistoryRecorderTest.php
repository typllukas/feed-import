<?php

declare(strict_types=1);

use App\Enums\PriceUpdateOutcome;
use App\Models\ProductPriceChange;
use App\Models\ProductPriceState;
use App\Services\PriceHistoryRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function recordAcmePrice(int $version, string $price, string $currency = 'CZK', string $sku = 'ACM-001', int $productId = 1): PriceUpdateOutcome
{
    return app(PriceHistoryRecorder::class)->record($productId, 'acme', $sku, $version, $price, $currency, 42);
}

/**
 * @return list<string>
 */
function findRecordedAcmePrices(): array
{
    return ProductPriceChange::where('product_id', 1)->orderBy('id')->pluck('price')->all();
}

it('records the opening price on the first message for a product', function (): void {
    expect(recordAcmePrice(1, '100.00'))->toBe(PriceUpdateOutcome::Recorded)
        ->and(findRecordedAcmePrices())->toBe(['100.00']);

    $priceState = ProductPriceState::where('product_id', 1)->sole();
    expect($priceState->version)->toBe(1)
        ->and($priceState->price)->toBe('100.00')
        ->and(ProductPriceChange::sole()->feed_import_id)->toBe(42);
});

it('records a row when a newer version changes the price', function (): void {
    recordAcmePrice(1, '100.00');

    expect(recordAcmePrice(2, '120.00'))->toBe(PriceUpdateOutcome::Recorded)
        ->and(findRecordedAcmePrices())->toBe(['100.00', '120.00']);
});

it('records nothing for a duplicate delivery', function (): void {
    recordAcmePrice(1, '100.00');

    expect(recordAcmePrice(1, '100.00'))->toBe(PriceUpdateOutcome::Stale)
        ->and(findRecordedAcmePrices())->toBe(['100.00']);
});

it('records nothing for a newer version with the same price, but remembers the version', function (): void {
    recordAcmePrice(1, '100.00');

    expect(recordAcmePrice(2, '100.00'))->toBe(PriceUpdateOutcome::Unchanged)
        ->and(findRecordedAcmePrices())->toBe(['100.00'])
        ->and(ProductPriceState::sole()->version)->toBe(2);
});

it('compares prices by value, not by how many decimals they are written with', function (): void {
    recordAcmePrice(1, '100.00');

    expect(recordAcmePrice(2, '100'))->toBe(PriceUpdateOutcome::Unchanged)
        ->and(recordAcmePrice(3, '100.5'))->toBe(PriceUpdateOutcome::Recorded)
        ->and(recordAcmePrice(4, '100.50'))->toBe(PriceUpdateOutcome::Unchanged)
        ->and(findRecordedAcmePrices())->toBe(['100.00', '100.50']);
});

it('records a row when only the currency changes', function (): void {
    recordAcmePrice(1, '100.00', 'CZK');

    expect(recordAcmePrice(2, '100.00', 'EUR'))->toBe(PriceUpdateOutcome::Recorded)
        ->and(ProductPriceChange::orderBy('id')->pluck('currency')->all())->toBe(['CZK', 'EUR']);
});

it('records every step when a price returns to an earlier value', function (): void {
    recordAcmePrice(1, '100.00');
    recordAcmePrice(2, '120.00');

    expect(recordAcmePrice(3, '100.00'))->toBe(PriceUpdateOutcome::Recorded)
        ->and(findRecordedAcmePrices())->toBe(['100.00', '120.00', '100.00']);
});

it('skips an older version that arrives after a newer one', function (): void {
    recordAcmePrice(2, '120.00');

    expect(recordAcmePrice(1, '100.00'))->toBe(PriceUpdateOutcome::Stale)
        ->and(findRecordedAcmePrices())->toBe(['120.00'])
        ->and(ProductPriceState::sole()->price)->toBe('120.00')
        ->and(ProductPriceState::sole()->version)->toBe(2);
});

it('records nothing when a batch of applied versions arrives again', function (): void {
    recordAcmePrice(1, '100.00');
    recordAcmePrice(2, '120.00');
    recordAcmePrice(3, '120.00');

    $replayOutcomes = [recordAcmePrice(1, '100.00'), recordAcmePrice(2, '120.00'), recordAcmePrice(3, '120.00')];

    expect($replayOutcomes)->each->toBe(PriceUpdateOutcome::Stale)
        ->and(findRecordedAcmePrices())->toBe(['100.00', '120.00']);
});

it('keeps the version of each product apart', function (): void {
    recordAcmePrice(5, '100.00');

    expect(recordAcmePrice(1, '30.00', sku: 'ACM-003', productId: 3))->toBe(PriceUpdateOutcome::Recorded)
        ->and(ProductPriceState::count())->toBe(2);
});

it('records a recreated product starting again at version 1', function (): void {
    recordAcmePrice(5, '100.00');

    expect(recordAcmePrice(1, '90.00', productId: 7))->toBe(PriceUpdateOutcome::Recorded)
        ->and(ProductPriceChange::where('product_id', 1)->pluck('price')->all())->toBe(['100.00'])
        ->and(ProductPriceChange::where('product_id', 7)->pluck('price')->all())->toBe(['90.00']);
});

it('keeps the price history in its own database, under a user that cannot read the importer one', function (): void {
    $importerTable = sprintf('`%s`.`products`', Config::string('database.connections.mysql.database'));

    expect(Schema::connection('price_history')->hasTable('product_price_changes'))->toBeTrue()
        ->and(Schema::hasTable('product_price_changes'))->toBeFalse()
        ->and(fn () => DB::connection('price_history')->select("SELECT COUNT(*) FROM {$importerTable}"))->toThrow(QueryException::class, 'denied');
});
