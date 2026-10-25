<?php

declare(strict_types=1);

namespace App\Providers;

use App\Database\SQLiteConnection;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use PDO;

/**
 * Runtime guards: Eloquent is strict outside production, so lazy loading,
 * discarded mass assignment and unloaded attributes fail in development and
 * tests while production stays lenient; destructive database commands are
 * prohibited in production. SQLite connections are built as
 * App\Database\SQLiteConnection, which rolls back a transaction whose commit
 * failed; the resolver is registered before any connection is made.
 */
final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Connection::resolverFor(
            'sqlite',
            /**
             * @param  PDO|(Closure(): PDO)  $pdo
             * @param  array<string, mixed>  $config
             */
            static fn (PDO|Closure $pdo, string $database, string $prefix, array $config): SQLiteConnection => new SQLiteConnection($pdo, $database, $prefix, $config),
        );
    }

    public function boot(): void
    {
        $isProduction = $this->app->environment('production');

        Model::shouldBeStrict(! $isProduction);
        DB::prohibitDestructiveCommands($isProduction);
    }
}
