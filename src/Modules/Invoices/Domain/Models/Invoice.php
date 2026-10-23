<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\Modules\Invoices\Domain\Models\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceMustBeCreatedAsDraft;
use Modules\Invoices\Domain\Exceptions\InvoiceNotSendable;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Override;

/**
 * Status is deliberately not fillable: mass assignment can never set it,
 * the creating hook pins draft, the updating hook rejects direct writes, and
 * the status transitions are the only way to change it.
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
     * @return HasMany<InvoiceProductLine, $this>
     */
    public function productLines(): HasMany
    {
        return $this->hasMany(InvoiceProductLine::class);
    }

    /**
     * Computed from the stored lines so it is never stale (one query per call).
     * A single line total fits in int because of InvoiceProductLine::MAX_VALUE;
     * the sum across lines may not, so the addition is checked.
     */
    public function totalPrice(): int
    {
        $total = 0;

        foreach ($this->productLines()->get() as $line) {
            $lineTotal = $line->totalUnitPrice();

            if ($lineTotal > PHP_INT_MAX - $total) {
                throw InvoiceTotalOverflow::forInvoice($this->id);
            }

            $total += $lineTotal;
        }

        return $total;
    }

    /**
     * The status is checked first, then every stored line against the line
     * invariant. The lines are read raw, so a row written past the model events
     * (0, 1.5, 'abc', above MAX_VALUE) is caught instead of being cast into
     * range; model writes already guarantee it, but the send rule reads
     * completely here.
     */
    public function ensureCanBeSent(): void
    {
        if ($this->status !== StatusEnum::Draft) {
            throw InvalidInvoiceStatusTransition::create($this->id, $this->status, StatusEnum::Sending);
        }

        $lines = $this->productLines()->get();

        if ($lines->isEmpty()) {
            throw InvoiceNotSendable::noProductLines($this->id);
        }

        if ($lines->contains(static fn (InvoiceProductLine $line): bool => ! self::hasValidAmounts($line))) {
            throw InvoiceNotSendable::invalidProductLine($this->id);
        }
    }

    public function markAsSending(): void
    {
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
     * Mass assignment cannot set status. The creating hook closes the
     * direct-assignment path on a new invoice, so it is always created as draft;
     * the updating hook closes it on a stored one ($invoice->status = ...;
     * $invoice->save()), so only the transitions change it. getOriginal() is
     * untyped, so the stored status is read from a copy hydrated from the
     * original attributes.
     */
    protected static function booted(): void
    {
        self::creating(static function (Invoice $invoice): void {
            if ($invoice->status !== StatusEnum::Draft) {
                throw InvoiceMustBeCreatedAsDraft::withStatus($invoice->status);
            }
        });
        self::updating(static function (Invoice $invoice): void {
            if (! $invoice->isDirty('status')) {
                return;
            }

            $stored = $invoice->newFromBuilder($invoice->getRawOriginal());

            throw InvalidInvoiceStatusTransition::create($invoice->id, $stored->status, $invoice->status);
        });
    }

    /**
     * The in-memory check gives a clear error before any write. The conditional
     * update is the atomic claim: of two instances holding the same status, only
     * one update matches the row. That needs no row lock, which SQLite does not
     * have (lockForUpdate compiles to nothing there). The Eloquent builder's
     * update bumps updated_at and fires no model events, so the updating hook
     * does not see it; syncing the original afterwards keeps a later save() on
     * this instance from tripping that hook.
     */
    private function transition(StatusEnum $from, StatusEnum $to): void
    {
        if ($this->status !== $from) {
            throw InvalidInvoiceStatusTransition::create($this->id, $this->status, $to);
        }

        $claimed = self::query()
            ->whereKey($this->id)
            ->where('status', $from->value)
            ->update(['status' => $to->value]);

        if ($claimed === 0) {
            $this->refresh();

            throw InvalidInvoiceStatusTransition::create($this->id, $this->status, $to);
        }

        $this->status = $to;
        $this->syncOriginalAttribute('status');
    }

    /**
     * The invariant InvoiceProductLine enforces on model writes: quantity and
     * unit price are integers between 1 and MAX_VALUE.
     */
    private static function hasValidAmounts(InvoiceProductLine $line): bool
    {
        $range = ['options' => ['min_range' => 1, 'max_range' => InvoiceProductLine::MAX_VALUE]];

        return array_all(['quantity', 'unit_price'], fn (string $field): bool => filter_var($line->getAttributes()[$field], FILTER_VALIDATE_INT, $range) !== false);
    }
}
