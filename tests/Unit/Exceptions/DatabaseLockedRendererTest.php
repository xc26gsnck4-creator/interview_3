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
    private const string LOCK_ERROR = 'SQLSTATE[HY000]: General error: 5 database is locked';

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
        $lockError = new PDOException(self::LOCK_ERROR);

        yield 'pdo exception' => [$lockError];
        yield 'query exception' => [new QueryException('sqlite', 'insert into "invoices" ("id") values (?)', ['1'], $lockError)];
    }

    public function test_ignores_other_database_errors(): void
    {
        $response = (new DatabaseLockedRenderer)(
            new PDOException('SQLSTATE[23000]: Integrity constraint violation'),
            Request::create('/api/invoices', 'POST'),
        );

        self::assertNull($response);
    }

    public function test_ignores_lock_errors_outside_the_api(): void
    {
        $response = (new DatabaseLockedRenderer)(new PDOException(self::LOCK_ERROR), Request::create('/'));

        self::assertNull($response);
    }
}
