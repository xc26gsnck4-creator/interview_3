<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\Modules\Invoices\Domain\Models\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Domain\Enums\InvoiceStatus;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceMustBeCreatedAsDraft;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceNotSendable;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Override;

/**
 * Status is not fillable: a new invoice takes the draft default, which the
 * creating hook enforces, and the application changes it only through the two
 * transitions. Nothing on the model stops a direct assignment and save() after
 * creation.
 *
 * @property string $id
 * @property string $customer_name
 * @property string $customer_email
 * @property InvoiceStatus $status
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
    protected $attributes = ['status' => InvoiceStatus::Draft->value];

    /**
     * Ids are stored lowercase and SQLite compares them case-sensitively.
     */
    public static function findOrThrow(string $invoiceId): self
    {
        return self::query()->find(strtolower($invoiceId)) ?? throw InvoiceNotFound::withId($invoiceId);
    }

    /**
     * Ordered by id, which is creation order: HasUuids generates UUIDv7 ids,
     * monotonic within one process.
     *
     * @return HasMany<InvoiceProductLine, $this>
     */
    public function productLines(): HasMany
    {
        return $this->hasMany(InvoiceProductLine::class)->orderBy('id');
    }

    /**
     * A line total always fits in int (Amount::MAX caps both factors), but the
     * sum may not, so only the addition is checked.
     */
    public function totalPrice(): int
    {
        $total = 0;

        foreach ($this->productLines as $line) {
            $lineTotal = $line->totalUnitPrice();

            if ($lineTotal > PHP_INT_MAX - $total) {
                throw InvoiceTotalOverflow::forInvoice($this->id);
            }

            $total += $lineTotal;
        }

        return $total;
    }

    /**
     * The stored amounts are checked again, as given, so a line written past
     * the cast, with the query builder, with an amount that is not an integer in
     * range cannot be sent.
     */
    public function ensureCanBeSent(): void
    {
        $this->ensureStatusIs(InvoiceStatus::Draft, InvoiceStatus::Sending);

        $lines = $this->load('productLines')->productLines;

        if ($lines->isEmpty()) {
            throw InvoiceNotSendable::noProductLines($this->id);
        }

        if (! $lines->every(static fn (InvoiceProductLine $line): bool => $line->hasValidAmounts())) {
            throw InvoiceNotSendable::invalidProductLine($this->id);
        }
    }

    /**
     * The claim guards only the status, because nothing in the application
     * writes lines after creation.
     */
    public function markAsSending(): void
    {
        $this->ensureCanBeSent();
        $this->transition(InvoiceStatus::Draft, InvoiceStatus::Sending);
    }

    public function markAsSentToClient(): void
    {
        $this->transition(InvoiceStatus::Sending, InvoiceStatus::SentToClient);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => InvoiceStatus::class];
    }

    protected static function booted(): void
    {
        self::creating(static function (Invoice $invoice): void {
            if ($invoice->status !== InvoiceStatus::Draft) {
                throw InvoiceMustBeCreatedAsDraft::withStatus($invoice->status);
            }
        });
    }

    private function ensureStatusIs(InvoiceStatus $from, InvoiceStatus $to): void
    {
        if ($this->status !== $from) {
            throw InvalidInvoiceStatusTransition::create($this->id, current: $this->status, expected: $from, target: $to);
        }
    }

    /**
     * The conditional update is the atomic claim: of two instances holding the
     * same status only one update matches the row, and SQLite has no row lock
     * to take instead (lockForUpdate compiles to nothing).
     */
    private function transition(InvoiceStatus $from, InvoiceStatus $to): void
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

            throw InvalidInvoiceStatusTransition::create($this->id, current: $this->status, expected: $from, target: $to);
        }

        $this->status = $to;
        $this->setUpdatedAt($now);
        $this->syncOriginalAttributes(['status', self::UPDATED_AT]);
    }
}
