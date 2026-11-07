<?php

declare(strict_types=1);

use App\Console\Commands\ConsumeProductUpdatesCommand;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\Product;
use App\Models\ProductPriceChange;
use App\Models\ProductPriceState;
use App\Models\RejectedRow;
use App\Services\PriceHistoryRecorder;

// one subject per expectation: with two, Pest miss a violation in the second
foreach ([ConsumeProductUpdatesCommand::class, PriceHistoryRecorder::class, ProductPriceState::class, ProductPriceChange::class] as $consumerClass) {
    arch(sprintf('%s uses none of the importer models', class_basename($consumerClass)))
        ->expect($consumerClass)
        ->not->toUse([Product::class, Partner::class, FeedImport::class, RejectedRow::class]);
}

arch('the importer uses none of the price history classes')
    ->expect([ProductPriceState::class, ProductPriceChange::class, PriceHistoryRecorder::class])
    ->toOnlyBeUsedIn([ConsumeProductUpdatesCommand::class, PriceHistoryRecorder::class, ProductPriceState::class, ProductPriceChange::class]);
