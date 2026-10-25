<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as ObjectSchema;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Infrastructure\Http\ExceptionHttpStatuses;
use Override;

/**
 * Documents the responses InvoiceServiceProvider renders for domain
 * exceptions: Scramble reads neither renderable() nor map(), and leaves out
 * an exception no extension handles. The statuses come from the map the
 * provider renders from. Scramble is a dev dependency, so this class lives in
 * autoload-dev and config/scramble.php registers it.
 */
final class InvoiceExceptionsToResponseExtension extends ExceptionToResponseExtension
{
    /**
     * @api Scramble calls it through method_exists(); the parent declares none.
     */
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && ($type->name === InvoiceTotalOverflow::class || array_key_exists($type->name, ExceptionHttpStatuses::STATUSES));
    }

    /**
     * An overflowing total is rendered as a validation error, so it is
     * documented as Scramble documents a ValidationException. Schema::fromType()
     * declares no return type, hence the instanceof.
     */
    #[Override]
    public function toResponse(Type $type): Response|Reference|null
    {
        if (! $type instanceof ObjectType) {
            return null;
        }

        if ($type->name === InvoiceTotalOverflow::class) {
            return $this->openApiTransformer->toResponse(new ObjectType(ValidationException::class));
        }

        $status = ExceptionHttpStatuses::STATUSES[$type->name];
        $body = new ObjectSchema;
        $body->addProperty('message', new StringType()->setDescription('Error overview.'));
        $body->setRequired(['message']);

        $schema = Schema::fromType($body);

        return $schema instanceof Schema
            ? Response::make($status)->setDescription(HttpResponse::$statusTexts[$status])->setContent('application/json', $schema)
            : null;
    }
}
