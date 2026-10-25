<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\DetectsConcurrencyErrors;
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
 * api routes only; a QueryException is a PDOException too. The exception is
 * still reported (Laravel's default).
 */
final class DatabaseLockedRenderer
{
    use DetectsConcurrencyErrors;

    public function __invoke(PDOException $exception, Request $request): ?JsonResponse
    {
        if ($request->is('api/*') && $this->causedByConcurrencyError($exception)) {
            return new JsonResponse(['message' => 'The database is busy; retry the request.'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }

        return null;
    }
}
