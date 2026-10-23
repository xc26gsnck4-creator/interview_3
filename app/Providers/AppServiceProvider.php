<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

/**
 * Application-wide runtime guards. Outside production Eloquent throws on lazy
 * loading, on silently discarded mass-assigned attributes and on reading an
 * attribute that was not loaded, so those bugs surface in development and tests
 * while production stays lenient. In production the destructive database
 * commands (db:wipe, migrate:fresh, migrate:refresh, migrate:reset,
 * migrate:rollback) refuse to run.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $isProduction = app()->isProduction();

        Model::shouldBeStrict(! $isProduction);
        DB::prohibitDestructiveCommands($isProduction);
    }
}
