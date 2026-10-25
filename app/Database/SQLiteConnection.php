<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Database\SQLiteConnection as BaseSQLiteConnection;
use Override;
use Throwable;

/**
 * SQLite keeps a transaction open when its COMMIT fails, for example with
 * "database is locked" while a reader holds its lock past the busy timeout.
 * Laravel rolls such a transaction back only before a retry, so with a single
 * attempt the connection would keep SQLite's write lock and block every other
 * writer until it is closed. Here it is rolled back before the exception
 * propagates: the transaction's writes are discarded and the lock is released.
 */
final class SQLiteConnection extends BaseSQLiteConnection
{
    #[Override]
    protected function handleCommitTransactionException(Throwable $e, mixed $currentAttempt, mixed $maxAttempts): void
    {
        try {
            parent::handleCommitTransactionException($e, $currentAttempt, $maxAttempts);
        } catch (Throwable $throwable) {
            $pdo = $this->getPdo();

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $throwable;
        }
    }
}
