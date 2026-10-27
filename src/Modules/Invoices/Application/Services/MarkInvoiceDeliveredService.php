<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * The lookup runs inside the transaction, which takes SQLite's write lock at
 * BEGIN (config/database.php), so a report that arrives while a send still
 * holds the lock waits for its commit and finds the invoice in sending, not the
 * draft a read outside a transaction would see.
 */
final readonly class MarkInvoiceDeliveredService
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function handle(string $invoiceId): Invoice
    {
        return $this->connection->transaction(static function () use ($invoiceId): Invoice {
            $invoice = Invoice::findOrThrow($invoiceId);
            $invoice->markAsSentToClient();

            return $invoice;
        });
    }
}
