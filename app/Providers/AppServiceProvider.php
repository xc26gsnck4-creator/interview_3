<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

/**
 * Eloquent is strict outside production, so lazy loading, discarded mass
 * assignment and unloaded attributes fail in development and tests while
 * production stays lenient.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->environment('production'));
    }
}
