<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyApiToken;
use App\Http\Resources\RejectedRowResource;
use App\Models\FeedImport;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware(VerifyApiToken::class)]
final class RejectedRowController extends Controller
{
    public function index(FeedImport $feedImport): AnonymousResourceCollection
    {
        return RejectedRowResource::collection($feedImport->rejectedRows()->inLineOrder()->paginate());
    }
}
