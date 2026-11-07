<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Connection('price_history')]
#[Fillable(['product_id', 'partner_code', 'sku', 'price', 'currency', 'feed_import_id'])]
class ProductPriceChange extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'price' => 'decimal:2',
            'feed_import_id' => 'integer',
        ];
    }
}
