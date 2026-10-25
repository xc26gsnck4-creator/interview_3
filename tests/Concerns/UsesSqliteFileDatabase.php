<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Runs the application on a temporary SQLite file, so other connections can
 * hold SQLite's locks while a request runs: the suite's :memory: database has
 * a single connection. Every connection is built from the application's sqlite
 * configuration (`url` cleared, so it cannot point elsewhere) with a 100 ms
 * busy timeout, which keeps each wait for a lock short. The test calls
 * useSqliteFileDatabase() in setUp() and dropSqliteFileDatabase() in
 * tearDown() before parent::tearDown(), because RefreshDatabase rolls back the
 * default connection by name. The calls are explicit: the dead-code detector
 * cannot see Laravel's setUp<Trait> hooks.
 */
trait UsesSqliteFileDatabase
{
    private const string SQLITE_FILE_CONNECTION = 'sqlite_file';

    private const int SQLITE_FILE_BUSY_TIMEOUT_MS = 100;

    private string $sqliteFilePath;

    /**
     * @var list<string>
     */
    private array $sqliteFileConnections = [];

    protected function useSqliteFileDatabase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sqlite-file-');
        self::assertIsString($path);
        $this->sqliteFilePath = $path;

        Config::set('database.connections.'.self::SQLITE_FILE_CONNECTION, [
            ...Config::array('database.connections.sqlite'),
            'url' => null,
            'database' => $path,
            'busy_timeout' => self::SQLITE_FILE_BUSY_TIMEOUT_MS,
        ]);
        $this->sqliteFileConnections[] = self::SQLITE_FILE_CONNECTION;
        Config::set('database.default', self::SQLITE_FILE_CONNECTION);

        self::assertSame(0, Artisan::call('migrate', ['--database' => self::SQLITE_FILE_CONNECTION, '--force' => true]));
    }

    /**
     * Another connection on the same file, such as a holder of the write lock
     * (IMMEDIATE) or a reader that takes only the shared lock (DEFERRED).
     */
    protected function sqliteFileConnection(string $name, string $transactionMode): ConnectionInterface
    {
        $connection = DB::connectUsing($name, [
            ...Config::array('database.connections.'.self::SQLITE_FILE_CONNECTION),
            'transaction_mode' => $transactionMode,
        ]);
        $this->sqliteFileConnections[] = $name;

        return $connection;
    }

    /**
     * Purging closes the connections, which rolls back a transaction one of
     * them still has open, so the file can be deleted. The path is unset when
     * setUp() failed before creating the file.
     */
    protected function dropSqliteFileDatabase(): void
    {
        foreach ($this->sqliteFileConnections as $name) {
            DB::purge($name);
        }

        Config::set('database.default', 'sqlite');

        if (isset($this->sqliteFilePath)) {
            File::delete($this->sqliteFilePath);
        }
    }
}
