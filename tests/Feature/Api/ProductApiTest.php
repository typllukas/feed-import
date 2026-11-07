<?php

declare(strict_types=1);

use App\Models\Partner;
use App\Models\Product;
use Illuminate\Support\Facades\Config;

beforeEach(function (): void {
    $this->withHeader('X-Api-Token', Config::string('feed.api_token'));
});

it('lists only the products of the partner in the url', function (): void {
    $partner = Partner::factory()->create();
    Product::factory()->for($partner)->count(2)->create();
    Product::factory()->for(Partner::factory())->create();

    $this->getJson("/api/partners/{$partner->id}/products")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('filters the products in stock, with the price as a string', function (): void {
    $partner = Partner::factory()->create();
    Product::factory()->for($partner)->create(['sku' => 'A-1', 'stock' => 0]);
    Product::factory()->for($partner)->create(['sku' => 'A-2', 'stock' => 3, 'price' => '249.9']);

    $this->getJson("/api/partners/{$partner->id}/products?in_stock=1")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sku', 'A-2')
        ->assertJsonPath('data.0.price', '249.90');
});

it('refuses an in_stock value other than 1 or 0 instead of reading it as false', function (): void {
    $partner = Partner::factory()->create();

    $this->getJson("/api/partners/{$partner->id}/products?in_stock=y")
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['in_stock']);
});

it('reads an empty in_stock as no filter', function (): void {
    $partner = Partner::factory()->has(Product::factory()->count(2))->create();

    $this->getJson("/api/partners/{$partner->id}/products?in_stock=")
        ->assertOk()
        ->assertJsonCount(2, 'data');
});
