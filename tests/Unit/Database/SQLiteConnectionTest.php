<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Database\SQLiteConnection;
use Illuminate\Support\Facades\Exceptions;
use PDO;
use PDOException;
use Tests\TestCase;

final class SQLiteConnectionTest extends TestCase
{
    /**
     * A rollback cannot be made to fail on a real SQLite connection, so the
     * PDO is a mock whose commit and rollback both fail. The commit's
     * exception propagates without a second attempt, the rollback's is
     * reported, and the handle is dropped, which makes SQLite release its lock.
     */
    public function test_failed_rollback_after_a_failed_commit_is_reported_and_drops_the_handle(): void
    {
        Exceptions::fake();
        $rollbackFailure = new PDOException('cannot rollback - no transaction is active');
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('commit')
            ->willThrowException(new PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->method('rollBack')->willThrowException($rollbackFailure);
        $connection = new SQLiteConnection($pdo);

        $this->assertThrows(
            static function () use ($connection): void {
                $connection->transaction(static function (): void {}, 2);
            },
            PDOException::class,
            'database is locked',
        );

        Exceptions::assertReported(static fn (PDOException $reported): bool => $reported === $rollbackFailure);
        self::assertSame(0, $connection->transactionLevel());
        self::assertNull($connection->getRawPdo());
    }
}
