<?php

declare(strict_types=1);

use App\Models\FeedImport;
use App\Models\Partner;
use App\Models\Product;
use App\Models\RejectedRow;

it('links products and feed imports to a partner', function (): void {
    $partner = Partner::factory()
        ->has(Product::factory()->count(3))
        ->has(FeedImport::factory()->has(RejectedRow::factory()->count(2)))
        ->create();

    expect($partner->products)->toHaveCount(3)
        ->and($partner->feedImports)->toHaveCount(1)
        ->and($partner->feedImports->first()?->rejectedRows)->toHaveCount(2);
});

it('deletes products, imports and rejected rows with the partner', function (): void {
    $partner = Partner::factory()
        ->has(Product::factory()->count(2))
        ->has(FeedImport::factory()->has(RejectedRow::factory()))
        ->create();
    expect(Product::count())->toBe(2)
        ->and(FeedImport::count())->toBe(1)
        ->and(RejectedRow::count())->toBe(1);

    $partner->delete();

    expect(Product::count())->toBe(0)
        ->and(FeedImport::count())->toBe(0)
        ->and(RejectedRow::count())->toBe(0);
});
