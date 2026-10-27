<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\Modules\Invoices\Domain\Models\InvoiceProductLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Domain\Exceptions\InvalidProductLine;

/**
 * `invoice_id` is deliberately not fillable: lines are created through
 * Invoice::productLines().
 *
 * @property string $id
 * @property string $invoice_id
 * @property string $name
 * @property int $quantity
 * @property int $unit_price
 */
#[Fillable(['name', 'quantity', 'unit_price'])]
final class InvoiceProductLine extends Model
{
    /** @use HasFactory<InvoiceProductLineFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * 2^31 - 1, so quantity * unit_price always fits in PHP's int.
     */
    public const int MAX_VALUE = 2_147_483_647;

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function totalUnitPrice(): int
    {
        return $this->quantity * $this->unit_price;
    }

    /**
     * The amounts rule as a check, so the send rule also catches a line written
     * past the model events.
     */
    public function hasValidAmounts(): bool
    {
        return self::isValidAmount($this->quantity) && self::isValidAmount($this->unit_price);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer'];
    }

    protected static function booted(): void
    {
        $assertValidAmounts = static function (InvoiceProductLine $line): void {
            foreach (['quantity' => $line->quantity, 'unit_price' => $line->unit_price] as $field => $value) {
                if (! self::isValidAmount($value)) {
                    throw InvalidProductLine::outOfRange($field, $value);
                }
            }
        };

        self::creating($assertValidAmounts);
        self::updating($assertValidAmounts);
    }

    /**
     * Null on a new line whose amount is unset or null, because the integer cast
     * leaves null alone.
     */
    private static function isValidAmount(?int $value): bool
    {
        return $value !== null && $value >= 1 && $value <= self::MAX_VALUE;
    }
}
