<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyApiToken;
use App\Http\Requests\IndexProductRequest;
use App\Http\Resources\ProductResource;
use App\Models\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware(VerifyApiToken::class)]
final class ProductController extends Controller
{
    public function index(IndexProductRequest $request, Partner $partner): AnonymousResourceCollection
    {
        $products = $partner->products()
            ->when($request->boolean('in_stock'), fn (Builder $query): Builder => $query->inStock())
            ->orderBy('sku')
            ->paginate();

        return ProductResource::collection($products);
    }
}
