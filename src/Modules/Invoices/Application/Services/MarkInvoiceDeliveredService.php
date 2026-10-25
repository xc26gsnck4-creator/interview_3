<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Services;

use Illuminate\Database\ConnectionInterface;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * Strict: an unknown invoice or one not in sending is an exception; the
 * webhook listener decides what to tolerate. The lookup and the transition
 * share a transaction, which begins IMMEDIATE (config/database.php) and so
 * takes SQLite's write lock before anything is read. A send notifies before it
 * commits, so a delivery report can arrive while the send still holds that
 * lock: it waits at BEGIN, up to the busy timeout, then finds the invoice in
 * sending. Read outside a transaction it would find the committed draft and
 * be rejected. A wait past the busy timeout fails at BEGIN with a
 * database-locked error, before anything is read. Under the lock nothing can
 * change or delete the row between the lookup and the transition.
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
