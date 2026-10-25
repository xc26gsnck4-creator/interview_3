<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;
use Override;
use Throwable;

/**
 * SQLite keeps a transaction open when its COMMIT fails, for example with
 * "database is locked" while a reader holds its lock past the busy timeout.
 * For a transaction run through transaction(), Laravel rolls it back only
 * before a retry, so with a single attempt the connection would keep SQLite's
 * write lock and block every other writer until it is closed. Here it is
 * rolled back before the exception propagates, so its writes are discarded
 * and the lock is released, and on both paths the transaction manager forgets
 * the failed transaction, so its afterCommit callbacks never run. A caller of
 * beginTransaction() and commit() keeps the transaction level after a failed
 * commit and rolls back itself.
 */
final class SQLiteConnection extends BaseSQLiteConnection
{
    #[Override]
    protected function handleCommitTransactionException(Throwable $e, mixed $currentAttempt, mixed $maxAttempts): void
    {
        try {
            parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);
        } catch (Throwable $throwable) {
            $this->rollBackOpenTransaction();

            throw $throwable;
        } finally {
            $this->forgetFailedTransaction();
        }
    }

    /**
     * The manager keys its records by connection name, which every connection
     * the database manager builds has.
     */
    private function forgetFailedTransaction(): void
    {
        $name = $this->getName();

        if ($name !== null) {
            $this->transactionsManager?->rollback($name, $this->transactions);
        }
    }

    /**
     * A rollback that fails too is reported, not thrown, so the commit's
     * exception stays the one that propagates.
     */
    private function rollBackOpenTransaction(): void
    {
        try {
            $pdo = $this->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
                $this->fireConnectionEvent('rollingBack');
            }
        } catch (Throwable $throwable) {
            report($throwable);
        }
    }
}
