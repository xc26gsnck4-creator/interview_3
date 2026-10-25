<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;

/**
 * SQLite transactions begin IMMEDIATE with a 5 s busy timeout
 * (config/database.php), so a writer that waits longer for the write lock
 * fails at BEGIN with a raw PDOException, before it has written anything, and
 * a commit that fails on the lock is rolled back (SQLiteConnection). A send
 * whose commit fails after the notification is SendNotRecorded instead, so
 * this 503 means nothing was written and the request can be retried.
 * bootstrap/app.php registers it for the whole application, and it answers on
 * api routes only. It recognises the lock by the driver code in errorInfo,
 * never by message text, which a client's own data could imitate in a query's
 * bindings; a QueryException is a PDOException too and copies errorInfo. The
 * exception is still reported (Laravel's default).
 */
final class DatabaseLockedRenderer
{
    /**
     * SQLite's result codes SQLITE_BUSY (5) and SQLITE_LOCKED (6).
     */
    private const array LOCK_CODES = [5, 6];

    public function __invoke(PDOException $exception, Request $request): ?JsonResponse
    {
        if ($request->is('api/*') && in_array($exception->errorInfo[1] ?? null, self::LOCK_CODES, true)) {
            return new JsonResponse(['message' => 'The database is busy; retry the request.'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return null;
    }
}
