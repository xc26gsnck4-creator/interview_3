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

    private const string READER = 'sqlite_lock_reader';

    private const int WAITER_BUSY_TIMEOUT_MS = 100;

    private string $databasePath;

    private ConnectionInterface $holder;

    private ConnectionInterface $waiter;

    private ConnectionInterface $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $path = tempnam(sys_get_temp_dir(), 'sqlite-transactions-');
        self::assertIsString($path);
        $this->databasePath = $path;

        $holder = [...Config::array('database.connections.sqlite'), 'url' => null, 'database' => $path];
        $this->holder = DB::connectUsing(self::HOLDER, $holder);

        $waiter = [...$holder, 'busy_timeout' => self::WAITER_BUSY_TIMEOUT_MS];
        $this->waiter = DB::connectUsing(self::WAITER, $waiter);

        $this->reader = DB::connectUsing(self::READER, [...$waiter, 'transaction_mode' => 'DEFERRED']);
    }

    /**
     * Purging closes the connections, which rolls back a transaction one of
     * them still has open, so the file can be deleted. The path is unset when
     * setUp() failed before creating the file.
     */
    protected function tearDown(): void
    {
        DB::purge(self::HOLDER);
        DB::purge(self::WAITER);
        DB::purge(self::READER);

        if (isset($this->databasePath)) {
            File::delete($this->databasePath);
        }

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

    /**
     * A reader holding SQLite's shared lock past the waiter's busy timeout
     * makes the waiter's COMMIT fail, and SQLite then keeps that transaction
     * open with the write lock. The reader begins DEFERRED so that it takes
     * only the shared lock. Once it has finished, its own write gets the lock,
     * and the waiter's insert is gone.
     */
    public function test_failed_commit_rolls_back_and_releases_the_write_lock(): void
    {
        Schema::connection(self::HOLDER)->create('locks', static function (Blueprint $table): void {
            $table->id();
        });
        $this->reader->beginTransaction();
        $this->reader->table('locks')->count();
        $this->assertThrows(
            fn () => $this->waiter->transaction(function (): void {
                $this->waiter->table('locks')->insert(['id' => 1]);
            }),
            PDOException::class,
            'database is locked',
        );
        $this->reader->commit();

        $this->reader->table('locks')->insert(['id' => 2]);

        self::assertSame([2], $this->reader->table('locks')->pluck('id')->all());
    }
}
