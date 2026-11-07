<?php

declare(strict_types=1);

use App\Http\Controllers\Api\FeedImportController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\RejectedRowController;
use Illuminate\Support\Facades\Route;

Route::apiResource('partners', PartnerController::class);
Route::apiResource('partners.feed-imports', FeedImportController::class)->shallow()->only(['index', 'store', 'show']);
Route::apiResource('feed-imports.rejected-rows', RejectedRowController::class)->only(['index']);
Route::apiResource('partners.products', ProductController::class)->only(['index']);
