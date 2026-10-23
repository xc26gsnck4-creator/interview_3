<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Runtime guards: Eloquent is strict outside production, so lazy loading,
 * discarded mass assignment and unloaded attributes fail in development and
 * tests while production stays lenient; destructive database commands are
 * prohibited in production.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $isProduction = $this->app->environment('production');

        Model::shouldBeStrict(! $isProduction);
        DB::prohibitDestructiveCommands($isProduction);
    }
}
