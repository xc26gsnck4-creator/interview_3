<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\Modules\Invoices\Domain\Models\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceMustBeCreatedAsDraft;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceNotSendable;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Override;

/**
 * Status is deliberately not fillable: mass assignment can never set it, and
 * the creating hook pins draft. After creation the status transitions are the
 * only code that changes it; nothing on the model stops a direct assignment
 * and save(). Quiet saves and query-builder writes bypass model events, as
 * Eloquent defines them.
 *
 * @property string $id
 * @property string $customer_name
 * @property string $customer_email
 * @property StatusEnum $status
 */
#[Fillable(['customer_name', 'customer_email'])]
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = ['status' => StatusEnum::Draft->value];

    /**
     * The lookup by id every use case shares: a missing invoice is reported as
     * InvoiceNotFound rather than Eloquent's ModelNotFoundException. A uuid is
     * case-insensitive on input, while ids are stored lowercase and SQLite
     * compares them case-sensitively, so the id is lowercased first.
     */
    public static function findOrThrow(string $invoiceId): self
    {
        return self::query()->find(strtolower($invoiceId)) ?? throw InvoiceNotFound::withId($invoiceId);
    }

    /**
     * Ordered by id, which is creation order: HasUuids generates UUIDv7, time
     * ordered and monotonic within one process. Without an ORDER BY the
     * database may return the lines in any order.
     *
     * @return HasMany<InvoiceProductLine, $this>
     */
    public function productLines(): HasMany
    {
        return $this->hasMany(InvoiceProductLine::class)->orderBy('id');
    }

    /**
     * Computed from the stored lines so it is never stale (one query per call).
     */
    public function totalPrice(): int
    {
        return $this->sumLineTotals($this->productLines()->get());
    }

    /**
     * Summed from the loaded `productLines` relation (Eloquent loads it when it
     * is not loaded yet). A response renders the total from the same lines it
     * lists and runs no query after a write's commit; totalPrice() keeps
     * reading the stored lines fresh.
     */
    public function totalPriceOfLoadedLines(): int
    {
        return $this->sumLineTotals($this->productLines);
    }

    /**
     * Each line total fits in int, but the sum of several may not; an invoice
     * whose total cannot be computed could never be shown or sent.
     */
    public function ensureTotalFits(): void
    {
        $this->totalPrice();
    }

    /**
     * The send rule, also usable as a dry check. The status is checked first.
     * The stored line amounts are checked again although model writes already
     * guarantee them, so the rule also catches a row written past the model
     * events.
     */
    public function ensureCanBeSent(): void
    {
        $this->ensureStatusIs(StatusEnum::Draft, StatusEnum::Sending);

        $lines = $this->productLines()->get();

        if ($lines->isEmpty()) {
            throw InvoiceNotSendable::noProductLines($this->id);
        }

        if (! $lines->every(static fn (InvoiceProductLine $line): bool => $line->hasValidAmounts())) {
            throw InvoiceNotSendable::invalidProductLine($this->id);
        }
    }

    /**
     * The send rule runs before the claim, so no caller can skip it. The claim
     * itself guards only the status: lines are supplied at creation and nothing
     * in the application writes them between the check and the claim.
     */
    public function markAsSending(): void
    {
        $this->ensureCanBeSent();
        $this->transition(StatusEnum::Draft, StatusEnum::Sending);
    }

    public function markAsSentToClient(): void
    {
        $this->transition(StatusEnum::Sending, StatusEnum::SentToClient);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => StatusEnum::class];
    }

    /**
     * Rejects a non-draft status on create, including one assigned directly
     * ($invoice->status = ...; $invoice->save()).
     */
    protected static function booted(): void
    {
        self::creating(static function (Invoice $invoice): void {
            if ($invoice->status !== StatusEnum::Draft) {
                throw InvoiceMustBeCreatedAsDraft::withStatus($invoice->status);
            }
        });
    }

    /**
     * A single line total fits in int because of InvoiceProductLine::MAX_VALUE;
     * the sum across lines may not, so the addition is checked.
     *
     * @param  Collection<int, InvoiceProductLine>  $lines
     */
    private function sumLineTotals(Collection $lines): int
    {
        $total = 0;

        foreach ($lines as $line) {
            $lineTotal = $line->totalUnitPrice();

            if ($lineTotal > PHP_INT_MAX - $total) {
                throw InvoiceTotalOverflow::forInvoice($this->id);
            }

            $total += $lineTotal;
        }

        return $total;
    }

    private function ensureStatusIs(StatusEnum $from, StatusEnum $to): void
    {
        if ($this->status !== $from) {
            throw InvalidInvoiceStatusTransition::create($this->id, $this->status, $to);
        }
    }

    /**
     * The in-memory check gives a clear error before any write. The conditional
     * update is the atomic claim: of two instances holding the same status, only
     * one update matches the row, with no row lock (SQLite compiles lockForUpdate
     * to nothing). It runs on the base query with an explicit timestamp, so the
     * instance gets exactly the updated_at the row got. The base query leaves the
     * instance's original values alone, so the written ones are synced as
     * original afterwards and a later save() does not write the status again.
     * A lost claim takes over only the stored status, leaving the instance's
     * other attributes and relations alone. The re-read sees the current row:
     * inside a transaction this connection holds
     * SQLite's write lock by now, taken at BEGIN when transactions begin
     * IMMEDIATE and by the zero-row update otherwise; outside one the update has
     * already committed.
     * Both queries run on this instance's connection.
     */
    private function transition(StatusEnum $from, StatusEnum $to): void
    {
        $this->ensureStatusIs($from, $to);

        $now = $this->freshTimestamp();
        $claimed = $this->newQuery()
            ->whereKey($this->id)
            ->where('status', $from->value)
            ->toBase()
            ->update(['status' => $to->value, self::UPDATED_AT => $now]);

        if ($claimed === 0) {
            $this->status = $this->newQuery()->whereKey($this->id)->firstOrFail(['status'])->status;
            $this->syncOriginalAttribute('status');

            throw InvalidInvoiceStatusTransition::create($this->id, $this->status, $to);
        }

        $this->status = $to;
        $this->setUpdatedAt($now);
        $this->syncOriginalAttributes(['status', self::UPDATED_AT]);
    }
}
