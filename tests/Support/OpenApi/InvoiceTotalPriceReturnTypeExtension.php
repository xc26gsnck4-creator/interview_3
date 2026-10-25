<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Infer\Extensions\Event\MethodCallEvent;
use Dedoc\Scramble\Infer\Extensions\MethodReturnTypeExtension;
use Dedoc\Scramble\Support\Type\IntegerType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * Scramble infers a method's return type from its body, and it does not follow
 * `$total += ...`, so it documents Invoice::totalPrice() and
 * Invoice::totalPriceOfLoadedLines() as the literal 0 the sum starts from.
 * This gives them the methods' declared type instead.
 * Registered from config/scramble.php.
 */
final class InvoiceTotalPriceReturnTypeExtension implements MethodReturnTypeExtension
{
    public function shouldHandle(ObjectType $type): bool
    {
        return $type->isInstanceOf(Invoice::class);
    }

    public function getMethodReturnType(MethodCallEvent $event): ?Type
    {
        return in_array($event->getName(), ['totalPrice', 'totalPriceOfLoadedLines'], true) ? new IntegerType : null;
    }
}
