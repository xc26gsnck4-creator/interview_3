<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * A rejected line or a total that does not fit in int rolls back the whole
 * invoice; totalPrice() is called for that check alone. The lines createMany()
 * returns become the loaded relation without another query, in the creation
 * order productLines() defines.
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

            $lines = $invoice->productLines()->createMany(array_map(
                static fn (ProductLineData $line): array => [
                    'name' => $line->name,
                    'quantity' => $line->quantity,
                    'unit_price' => $line->unitPrice,
                ],
                $data->productLines,
            ));
            $invoice->setRelation('productLines', $lines);
            $invoice->totalPrice();

            return $invoice;
        });
    }
}
