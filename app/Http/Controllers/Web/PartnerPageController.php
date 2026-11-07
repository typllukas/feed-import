<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Partner;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('auth')]
final class PartnerPageController extends Controller
{
    public function index(): View
    {
        $partners = Partner::query()
            ->withCount('products')
            ->with('latestFeedImports')
            ->alphabetical()
            ->get();

        return view('partners.index', ['partners' => $partners]);
    }
}
