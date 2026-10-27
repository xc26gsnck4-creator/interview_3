<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * Customer name and email arrive validated from the HTTP edge; the domain
 * owns the status and the line rules. A rejected line, or lines whose total
 * does not fit in int, roll back the whole invoice. The lines createMany()
 * returns become the loaded relation without another query: they are in
 * input order, which is id order (UUIDv7), the relation's order.
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

            $invoice->setRelation('productLines', $invoice->productLines()->createMany(array_map(
                static fn (ProductLineData $line): array => [
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ],
                $data->productLines,
            )));
            $invoice->totalPrice();

            return $invoice;
        });
    }
}
