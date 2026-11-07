<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\PriceUpdateOutcome;
use App\Models\ProductPriceChange;
use App\Models\ProductPriceState;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class PriceHistoryRecorder
{
    public function record(int $productId, string $partnerCode, string $sku, int $version, string $price, string $currency, int $feedImportId): PriceUpdateOutcome
    {
        return DB::connection('price_history')->transaction(function () use ($productId, $partnerCode, $sku, $version, $price, $currency, $feedImportId): PriceUpdateOutcome {
            // a version 0 row before the lock: locking a missing key takes a gap lock, and two new keys in one gap deadlock
            if (! ProductPriceState::query()->where('product_id', $productId)->exists()) {
                ProductPriceState::query()->insertOrIgnore([
                    'product_id' => $productId,
                    'version' => 0,
                    'price' => $price,
                    'currency' => $currency,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $priceState = ProductPriceState::query()
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->sole();

            if ($version <= $priceState->version) {
                return PriceUpdateOutcome::Stale;
            }

            $isNewPrice = $priceState->version === 0 || ! BigDecimal::of($priceState->price)->isEqualTo($price) || $priceState->currency !== $currency;
            if ($isNewPrice) {
                ProductPriceChange::create([
                    'product_id' => $productId,
                    'partner_code' => $partnerCode,
                    'sku' => $sku,
                    'price' => $price,
                    'currency' => $currency,
                    'feed_import_id' => $feedImportId,
                ]);
            }

            $priceState->fill(['version' => $version, 'price' => $price, 'currency' => $currency])->save();

            return $isNewPrice ? PriceUpdateOutcome::Recorded : PriceUpdateOutcome::Unchanged;
        }, attempts: 3);
    }
}
