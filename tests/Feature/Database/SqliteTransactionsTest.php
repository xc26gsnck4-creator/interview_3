<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\SQLiteBuilder;
use Illuminate\Support\Benchmark;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
        $this->createLocksTable();
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
     * Once the reader has finished, its own write gets the lock the waiter's
     * failed commit held, and the waiter's insert is gone.
     */
    public function test_failed_commit_rolls_back_and_releases_the_write_lock(): void
    {
        $this->createLocksTable();
        $rolledBack = [];
        Event::listen(static function (TransactionRolledBack $event) use (&$rolledBack): void {
            $rolledBack[] = $event->connectionName;
        });
        $this->failCommitWhileTheReaderHoldsItsLock(function (): void {
            $this->waiter->table('locks')->insert(['id' => 1]);
        });

        $this->reader->table('locks')->insert(['id' => 2]);

        self::assertSame([2], $this->reader->table('locks')->pluck('id')->all());
        self::assertSame([self::WAITER], $rolledBack);
    }

    /**
     * A callback registered for after the commit belongs to the transaction
     * whose commit failed, so the next successful transaction must not run it.
     */
    public function test_failed_commit_drops_the_after_commit_callbacks_of_its_transaction(): void
    {
        $this->createLocksTable();
        $afterCommitRan = false;
        $this->failCommitWhileTheReaderHoldsItsLock(function () use (&$afterCommitRan): void {
            DB::connection(self::WAITER)->afterCommit(static function () use (&$afterCommitRan): void {
                $afterCommitRan = true;
            });
            $this->waiter->table('locks')->insert(['id' => 1]);
        });

        $this->waiter->transaction(function (): void {
            $this->waiter->table('locks')->insert(['id' => 2]);
        });

        self::assertFalse($afterCommitRan);
    }

    /**
     * Laravel's connection works without a transaction manager; then there is
     * nothing to forget, and the rollback still releases the lock.
     */
    public function test_failed_commit_rolls_back_on_a_connection_without_a_transaction_manager(): void
    {
        $this->createLocksTable();
        DB::connection(self::WAITER)->unsetTransactionManager();
        $this->failCommitWhileTheReaderHoldsItsLock(function (): void {
            $this->waiter->table('locks')->insert(['id' => 1]);
        });

        $this->reader->table('locks')->insert(['id' => 2]);

        self::assertSame([2], $this->reader->table('locks')->pluck('id')->all());
    }

    private function createLocksTable(): void
    {
        Schema::connection(self::HOLDER)->create('locks', static function (Blueprint $table): void {
            $table->id();
        });
    }

    /**
     * A reader holding SQLite's shared lock past the waiter's busy timeout
     * makes the waiter's COMMIT fail, and SQLite then keeps that transaction
     * open with the write lock. The reader begins DEFERRED so that it takes
     * only the shared lock, and finishes once the commit has failed.
     *
     * @param  Closure(): void  $work
     */
    private function failCommitWhileTheReaderHoldsItsLock(Closure $work): void
    {
        $this->reader->beginTransaction();
        $this->reader->table('locks')->count();

        $this->assertThrows(
            function () use ($work): void {
                $this->waiter->transaction($work);
            },
            PDOException::class,
            'database is locked',
        );

        $this->reader->commit();
    }
}
