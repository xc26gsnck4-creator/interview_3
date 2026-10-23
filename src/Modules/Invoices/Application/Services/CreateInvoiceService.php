<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * Customer name and email arrive validated from the HTTP edge; the domain
 * owns the status and the line rules. A rejected line rolls back the whole
 * invoice, so a create either persists everything or nothing.
 */
final readonly class CreateInvoiceService
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function handle(CreateInvoiceData $data): Invoice
    {
        return $this->connection->transaction(static function () use ($data): Invoice {
            $invoice = Invoice::query()->create([
                'customer_name' => $data->customerName,
                'customer_email' => $data->customerEmail,
            ]);

            foreach ($data->productLines as $line) {
                $invoice->productLines()->create([
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ]);
            }

            return $invoice->load('productLines');
        });
    }
}
