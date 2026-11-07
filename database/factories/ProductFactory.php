<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Partner;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * @return array<model-property<Product>, mixed>
     */
    public function definition(): array
    {
        return [
            'partner_id' => Partner::factory(),
            'sku' => sprintf('SKU-%05d', fake()->unique()->numberBetween(1, 99999)),
            'name' => fake()->words(3, true),
            'price' => fake()->randomFloat(2, 1, 999),
            'currency' => 'CZK',
            'stock' => fake()->numberBetween(0, 500),
        ];
    }
}
