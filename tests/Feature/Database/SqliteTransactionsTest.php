<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use LogicException;
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

    private ConnectionInterface $holder;

    private ConnectionInterface $waiter;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'sqlite-transactions-');
        self::assertIsString($path);
        $this->databasePath = $path;

        $holder = [...Config::array('database.connections.sqlite'), 'database' => $path];
        $this->holder = DB::connectUsing(self::HOLDER, $holder);

        $this->waiter = DB::connectUsing(self::WAITER, [...$holder, 'busy_timeout' => self::WAITER_BUSY_TIMEOUT_MS]);
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
     * notifies, so its lock does not depend on the mode under test. Under
     * DEFERRED the waiter's BEGIN would succeed and its callback would throw.
     */
    public function test_transaction_fails_at_begin_before_its_callback_runs_while_another_connection_writes(): void
    {
        Schema::connection(self::HOLDER)->create('locks', static function (Blueprint $table): void {
            $table->id();
        });
        $this->holder->beginTransaction();
        $this->holder->table('locks')->insert(['id' => 1]);

        [, $elapsedMs] = Benchmark::value(fn () => $this->assertThrows(
            fn () => $this->waiter->transaction(static function (): never {
                throw new LogicException('The callback ran.');
            }),
            PDOException::class,
            'database is locked',
        ));

        self::assertGreaterThanOrEqual(self::WAITER_BUSY_TIMEOUT_MS, $elapsedMs);
    }
}
