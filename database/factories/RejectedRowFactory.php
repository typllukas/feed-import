<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FeedImport;
use App\Models\RejectedRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RejectedRow>
 */
class RejectedRowFactory extends Factory
{
    /**
     * @return array<model-property<RejectedRow>, mixed>
     */
    public function definition(): array
    {
        return [
            'feed_import_id' => FeedImport::factory(),
            'line_number' => fake()->numberBetween(2, 200),
            'message' => 'The price field must be at least 0.',
            'row' => ['sku' => sprintf('SKU-%05d', fake()->numberBetween(1, 99999)), 'name' => fake()->words(2, true), 'price' => '-12.90', 'currency' => 'CZK', 'stock' => '4'],
        ];
    }
}
