<?php

declare(strict_types=1);

namespace Tests\Unit\Exceptions;

use App\Exceptions\DatabaseLockedRenderer;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Iterator;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DatabaseLockedRendererTest extends TestCase
{
    /**
     * A lock error at BEGIN is a raw PDOException; a query that fails on the
     * lock is a QueryException, which is a PDOException too.
     */
    #[DataProvider('lockErrors')]
    public function test_answers_503_for_a_lock_error_on_an_api_route(PDOException $exception): void
    {
        $response = (new DatabaseLockedRenderer)($exception, Request::create('/api/invoices', 'POST'));

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame(['message' => 'The database is busy; retry the request.'], $response->getData(true));
    }

    /**
     * @return Iterator<string, array{PDOException}>
     */
    public static function lockErrors(): Iterator
    {
        $busy = self::pdoExceptionWith(['HY000', 5, 'database is locked']);

        yield 'busy' => [$busy];
        yield 'locked' => [self::pdoExceptionWith(['HY000', 6, 'database table is locked'])];
        yield 'query exception' => [new QueryException('sqlite', 'insert into "invoices" ("id") values (?)', ['1'], $busy)];
    }

    /**
     * @return Iterator<string, array{PDOException}>
     */
    public static function otherErrors(): Iterator
    {
        yield 'client text in the bindings' => [new QueryException(
            'sqlite',
            'insert into "invoices" ("customer_name") values (?)',
            ['database is locked'],
            self::pdoExceptionWith(['HY000', 8, 'attempt to write a readonly database']),
        )];
        yield 'no error info' => [new PDOException('SQLSTATE[HY000]: General error: 5 database is locked')];
    }

    #[DataProvider('otherErrors')]
    public function test_ignores_other_database_errors(PDOException $exception): void
    {
        $response = (new DatabaseLockedRenderer)($exception, Request::create('/api/invoices', 'POST'));

        self::assertNull($response);
    }

    public function test_ignores_lock_errors_outside_the_api(): void
    {
        $response = (new DatabaseLockedRenderer)(self::pdoExceptionWith(['HY000', 5, 'database is locked']), Request::create('/'));

        self::assertNull($response);
    }

    /**
     * @param  array{string, int, string}  $errorInfo
     */
    private static function pdoExceptionWith(array $errorInfo): PDOException
    {
        $exception = new PDOException($errorInfo[2]);
        $exception->errorInfo = $errorInfo;

        return $exception;
    }
}
