<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $price decimal:2 cast; Larastan reads the column as float
 */
#[Connection('price_history')]
#[Fillable(['version', 'price', 'currency'])]
class ProductPriceState extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'price' => 'decimal:2',
        ];
    }
}
