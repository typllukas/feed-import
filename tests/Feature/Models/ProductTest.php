<?php

declare(strict_types=1);

use App\Models\Product;

it('filters products in stock through a scope', function (): void {
    Product::factory()->create(['stock' => 0]);
    Product::factory()->create(['stock' => 4]);

    expect(Product::inStock()->count())->toBe(1);
});

it('keeps the price as a string with two decimals', function (): void {
    $product = Product::factory()->create(['price' => '249.9']);

    expect($product->refresh()->price)->toBe('249.90');
});
