<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * Strict: the webhook listener decides what to tolerate. The lookup runs in
 * the transaction, which takes SQLite's write lock at BEGIN, so a report that
 * arrives while a send still holds the lock waits for its commit and finds the
 * invoice in sending rather than the committed draft.
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
