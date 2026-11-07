<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PartnerController;
use Illuminate\Support\Facades\Route;

Route::apiResource('partners', PartnerController::class);
