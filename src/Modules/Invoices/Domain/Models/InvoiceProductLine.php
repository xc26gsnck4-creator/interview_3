<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\Modules\Invoices\Domain\Models\InvoiceProductLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Domain\ValueObjects\Amount;

/**
 * `invoice_id` is deliberately not fillable: lines are created through
 * Invoice::productLines().
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $name
 * @property Amount $quantity
 * @property Amount $unit_price
 */
#[Fillable(['name', 'quantity', 'unit_price'])]
final class InvoiceProductLine extends Model
{
    /** @use HasFactory<InvoiceProductLineFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function totalUnitPrice(): int
    {
        return $this->quantity->value * $this->unit_price->value;
    }

    /**
     * Read as given, not through the cast, which throws on a stored fraction:
     * the send rule answers 422 for such a row, not 500.
     */
    public function hasValidAmounts(): bool
    {
        $attributes = $this->getAttributes();

        return Amount::tryFrom($attributes['quantity'] ?? null) !== null && Amount::tryFrom($attributes['unit_price'] ?? null) !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => Amount::class, 'unit_price' => Amount::class];
    }
}
