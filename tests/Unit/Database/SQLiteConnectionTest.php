<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\SQLiteConnection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\Exceptions;
use PDO;
use PDOException;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\TestCase;

/**
 * A rollback cannot be made to fail on a real SQLite connection, so the PDO
 * is a mock whose commit and rollback both fail.
 */
final class SQLiteConnectionTest extends TestCase
{
    private PDOException $rollbackFailure;

    private PDO&MockObject $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        Exceptions::fake();
        $this->rollbackFailure = new PDOException('cannot rollback - no transaction is active');
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->expects($this->once())
            ->method('commit')
            ->willThrowException(new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->method('rollBack')->willThrowException($this->rollbackFailure);
    }

    /**
     * The commit's exception propagates without a second attempt, the
     * rollback's is reported, and the handle is dropped, which makes SQLite
     * release its lock.
     */
    public function test_failed_rollback_after_a_failed_commit_is_reported_and_drops_the_handle(): void
    {
        $connection = new SQLiteConnection($this->pdo);

        $this->assertThrows(
            static function () use ($connection): void {
                $connection->transaction(static function (): void {}, 2);
            },
            PDOException::class,
            'database is locked',
        );

        Exceptions::assertReported(fn (PDOException $reported): bool => $reported === $this->rollbackFailure);
        self::assertSame(0, $connection->transactionLevel());
        self::assertNull($connection->getRawPdo());
    }

    /**
     * Forgetting the transaction runs its afterRollBack callbacks. The handle
     * is closed before that, so a callback that throws cannot keep SQLite's
     * lock, and the rollback failure is still reported.
     */
    public function test_failed_rollback_closes_the_handle_before_after_roll_back_callbacks_run(): void
    {
        $connection = new SQLiteConnection($this->pdo);
        $connection->setTransactionManager(new DatabaseTransactionsManager);

        $this->assertThrows(
            static function () use ($connection): void {
                $connection->transaction(static function () use ($connection): void {
                    $connection->afterRollBack(static function (): never {
                        throw new RuntimeException('The callback failed.');
                    });
                });
            },
            RuntimeException::class,
            'The callback failed.',
        );

        self::assertNull($connection->getRawPdo());
        Exceptions::assertReported(fn (PDOException $reported): bool => $reported === $this->rollbackFailure);
    }
}
