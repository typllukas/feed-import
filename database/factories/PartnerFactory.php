<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Partner;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Partner>
 */
class PartnerFactory extends Factory
{
    /**
     * @return array<model-property<Partner>, mixed>
     */
    public function definition(): array
    {
        $lastName = fake()->unique()->lastName();

        return [
            'name' => $lastName.' '.fake()->companySuffix(),
            'code' => Str::slug($lastName),
        ];
    }
}
