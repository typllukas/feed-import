<?php

declare(strict_types=1);

use App\Http\Controllers\Web\FeedImportPageController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\PartnerPageController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/partners');

Route::get('/login', [LoginController::class, 'create'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1')->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('/partners', [PartnerPageController::class, 'index'])->name('partners.index');
Route::get('/feed-imports/{feedImport}', [FeedImportPageController::class, 'show'])->name('feed-imports.show');
