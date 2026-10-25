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
 * before a retry and without its rollback bookkeeping, so with a single
 * attempt the connection would keep SQLite's write lock and block every other
 * writer until it is closed, and the failed transaction's afterCommit
 * callbacks would stay pending. Here every failed commit first goes through
 * Laravel's own rollBack(): the PDO rollback, the transaction level, the
 * transaction manager and the rollingBack event, in its order. Then Laravel
 * decides whether to retry. A listener or callback that throws after a
 * successful rollback propagates as it does from rollBack(). A caller of
 * beginTransaction() and commit() keeps the transaction level after a failed
 * commit and rolls back itself.
 */
final class SQLiteConnection extends BaseSQLiteConnection
{
    #[Override]
    protected function handleCommitTransactionException(Throwable $e, mixed $currentAttempt, mixed $maxAttempts): void
    {
        try {
            $this->rollBack();
        } catch (Throwable $throwable) {
            throw_if($this->transactionLevel() === 0, $throwable);

            $this->closeFailedTransaction($throwable);

            throw $e;
        }

        parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);
    }

    /**
     * The PDO rollback failed too (the level is still the committed one), so
     * SQLite still holds the transaction and its write lock. Closing the handle
     * first releases both, even if an afterRollBack callback then throws while
     * disconnect() makes the manager forget the transaction. The failure is
     * reported last, so a reporter that writes to this database cannot write
     * into the failed transaction. The commit's exception then propagates
     * without a retry, because a closed handle cannot be retried.
     */
    private function closeFailedTransaction(Throwable $rollbackFailure): void
    {
        try {
            $this->setPdo(null);
            $this->disconnect();
        } finally {
            report($rollbackFailure);
        }
    }
}
