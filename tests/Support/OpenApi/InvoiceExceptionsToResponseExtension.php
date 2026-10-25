<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Support\ExceptionToResponseExtensions\HttpExceptionToResponseExtension;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\Response;
use Modules\Invoices\Infrastructure\Http\ExceptionHttpStatuses;
use Override;

/**
 * Documents the responses InvoiceServiceProvider renders for its mapped
 * exceptions: Scramble reads neither renderable() nor map(), and leaves out
 * an exception no extension handles. The `{"message": ...}` body is the one
 * Scramble documents for an HTTP exception; the status comes from the maps the
 * provider renders from (STATUSES joins them). Scramble is a dev dependency,
 * so this class lives in autoload-dev and config/scramble.php registers it.
 */
final class InvoiceExceptionsToResponseExtension extends HttpExceptionToResponseExtension
{
    #[Override]
    public function shouldHandle(Type $type): bool
    {
        return $this->mappedStatus($type) !== null;
    }

    #[Override]
    protected function getResponseCode(?Type $codeType, Type $type): ?int
    {
        return $this->mappedStatus($type);
    }

    #[Override]
    protected function getDescription(Type $type): string
    {
        $status = $this->mappedStatus($type);

        return $status === null ? parent::getDescription($type) : Response::$statusTexts[$status];
    }

    private function mappedStatus(Type $type): ?int
    {
        return $type instanceof ObjectType ? ExceptionHttpStatuses::STATUSES[$type->name] ?? null : null;
    }
}
