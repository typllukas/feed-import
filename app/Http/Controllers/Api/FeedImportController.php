<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyApiToken;
use App\Http\Requests\StoreFeedImportRequest;
use App\Http\Resources\FeedImportResource;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Services\FeedImportStarter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware(VerifyApiToken::class)]
final class FeedImportController extends Controller
{
    public function index(Partner $partner): AnonymousResourceCollection
    {
        return FeedImportResource::collection($partner->feedImports()->recent()->paginate());
    }

    public function store(StoreFeedImportRequest $request, Partner $partner, FeedImportStarter $feedImportStarter): JsonResponse
    {
        $feedImport = $feedImportStarter->start($partner, $request->getFeedFile());

        return FeedImportResource::make($feedImport)->response()->setStatusCode(Response::HTTP_ACCEPTED);
    }

    public function show(FeedImport $feedImport): FeedImportResource
    {
        return FeedImportResource::make($feedImport);
    }
}
