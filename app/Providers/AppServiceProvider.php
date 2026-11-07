<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // lazy loading, unfillable and missing attributes throw outside production
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
