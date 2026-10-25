<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Tests\TestCase;

/**
 * The suite's :memory: database has a single connection, so locking is
 * observed on a temporary SQLite file through two connections built from the
 * application's sqlite configuration. Only the waiter's busy timeout differs,
 * which keeps the test short.
 */
final class SqliteTransactionsTest extends TestCase
{
    private const string HOLDER = 'sqlite_lock_holder';

    private const string WAITER = 'sqlite_lock_waiter';

    private const int WAITER_BUSY_TIMEOUT_MS = 100;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'sqlite-transactions-');
        self::assertIsString($path);
        $this->databasePath = $path;

        $sqlite = Config::array('database.connections.sqlite');
        Config::set('database.connections.'.self::HOLDER, [...$sqlite, 'database' => $path]);
        Config::set('database.connections.'.self::WAITER, [...$sqlite, 'database' => $path, 'busy_timeout' => self::WAITER_BUSY_TIMEOUT_MS]);
    }

    /**
     * Purging closes both connections, which rolls back a transaction the
     * holder still has open, so the file can be deleted.
     */
    protected function tearDown(): void
    {
        DB::purge(self::HOLDER);
        DB::purge(self::WAITER);
        File::delete($this->databasePath);

        parent::tearDown();
    }

    public function test_default_connection_waits_up_to_five_seconds_for_a_lock(): void
    {
        $schema = DB::connection()->getSchemaBuilder();

        self::assertInstanceOf(SQLiteBuilder::class, $schema);
        self::assertSame(5000, $schema->pragma('busy_timeout'));
    }

    /**
     * The holder writes inside an open transaction, as a send does while it
     * notifies. The waiter's callback reads before it writes, as a send does;
     * under DEFERRED it would run and its insert would fail without waiting.
     */
    public function test_transaction_fails_at_begin_before_its_callback_runs_while_another_connection_writes(): void
    {
        Schema::connection(self::HOLDER)->create('locks', static function (Blueprint $table): void {
            $table->id();
        });
        $holder = DB::connection(self::HOLDER);
        $holder->beginTransaction();
        $holder->table('locks')->insert(['id' => 1]);
        $waiter = DB::connection(self::WAITER);
        $callbackRan = false;
        $callback = static function () use ($waiter, &$callbackRan): void {
            $callbackRan = true;
            $waiter->table('locks')->first();
            $waiter->table('locks')->insert(['id' => 2]);
        };
        $startedAt = microtime(true);

        $this->assertThrows(
            static function () use ($waiter, $callback): void {
                $waiter->transaction($callback);
            },
            PDOException::class,
            'database is locked',
        );

        self::assertFalse($callbackRan);
        self::assertGreaterThanOrEqual(self::WAITER_BUSY_TIMEOUT_MS, (microtime(true) - $startedAt) * 1000);
    }
}
