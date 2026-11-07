<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PartnerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code'])]
class Partner extends Model
{
    /** @use HasFactory<PartnerFactory> */
    use HasFactory;

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @return HasMany<FeedImport, $this>
     */
    public function feedImports(): HasMany
    {
        return $this->hasMany(FeedImport::class);
    }

    /**
     * @return HasMany<FeedImport, $this>
     */
    public function latestFeedImports(): HasMany
    {
        // since Laravel 11 the limit applies per partner in an eager load, not to the whole query
        return $this->feedImports()->recent()->limit(5);
    }

    /**
     * @param  Builder<Partner>  $query
     */
    #[Scope]
    protected function alphabetical(Builder $query): void
    {
        // names repeat, so a page boundary needs the id to stay put
        $query->orderBy('name')->orderBy('id');
    }
}
