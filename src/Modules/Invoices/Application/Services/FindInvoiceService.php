<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Models\Invoice;

final readonly class FindInvoiceService
{
    public function handle(string $invoiceId): Invoice
    {
        return Invoice::query()->with('productLines')->find($invoiceId)
            ?? throw InvoiceNotFound::withId($invoiceId);
    }
}
