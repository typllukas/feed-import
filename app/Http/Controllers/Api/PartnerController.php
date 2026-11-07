<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyApiToken;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Requests\UpdatePartnerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Partner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware(VerifyApiToken::class)]
final class PartnerController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return PartnerResource::collection(Partner::withCount('products')->alphabetical()->paginate());
    }

    public function store(StorePartnerRequest $request): JsonResponse
    {
        $partner = Partner::create($request->validated());

        return PartnerResource::make($partner->loadCount('products'))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Partner $partner): PartnerResource
    {
        return PartnerResource::make($partner->loadCount('products'));
    }

    public function update(UpdatePartnerRequest $request, Partner $partner): PartnerResource
    {
        $partner->update($request->validated());

        return PartnerResource::make($partner->loadCount('products'));
    }

    public function destroy(Partner $partner): Response
    {
        $partner->delete();

        return response()->noContent();
    }
}
