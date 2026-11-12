<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\FeedImport;
use Illuminate\Contracts\View\View;
use Illuminate\Routing\Attributes\Controllers\Middleware;

#[Middleware('auth')]
final class FeedImportPageController extends Controller
{
    public function show(FeedImport $feedImport): View
    {
        // not $errors, Blade shares the validation MessageBag under that name in every view
        return view('feed-imports.show', [
            'feedImport' => $feedImport->load('partner'),
            'rejectedRows' => $feedImport->rejectedRows()->inLineOrder()->simplePaginate(25),
        ]);
    }
}
