<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeedImportResource;
use App\Models\FeedImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('auth')]
final class FeedImportPageController extends Controller
{
    /**
     * Without Vary, Back after the component's polling shows the cached JSON instead of the page.
     */
    public function show(Request $request, FeedImport $feedImport): JsonResponse|Response
    {
        if ($request->wantsJson()) {
            return FeedImportResource::make($feedImport)->response()->header('Vary', 'Accept');
        }

        return response()->view('feed-imports.show', ['feedImport' => $feedImport->load('partner')])->header('Vary', 'Accept');
    }
}
