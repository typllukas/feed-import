<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeedImportRequest;
use App\Http\Resources\FeedImportResource;
use App\Models\FeedImport;
use App\Models\Partner;
use App\Services\FeedImportStarter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\File;

#[Middleware('auth')]
final class FeedImportPageController extends Controller
{
    public function create(Partner $partner): View
    {
        return view('feed-imports.create', [
            'partner' => $partner,
            'sampleFeed' => app()->isLocal() ? File::get(database_path('seeders/feeds/acme.csv')) : null,
        ]);
    }

    public function store(StoreFeedImportRequest $request, Partner $partner, FeedImportStarter $feedImportStarter): RedirectResponse
    {
        return redirect()->route('feed-imports.show', $feedImportStarter->start($partner, $request->getFeedFile()));
    }

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
