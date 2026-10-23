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
     * The `integer` column range; also keeps quantity * unit_price inside PHP's int.
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'integer'];
    }

    /**
     * Line amounts are set, never incremented or decremented: the SQL arithmetic
     * runs against the stored value and cannot be validated in memory. Covers
     * increment, decrement and their quiet variants. The other parameters
     * inherit the parent's PHPDoc; the parent documents $extra as a bare array.
     *
     * @param  array<mixed>  $extra
     */
    protected function incrementOrDecrement($column, $amount, $extra, $method): never
    {
        throw InvalidProductLine::arithmeticNotAllowed($column);
    }

    /**
     * Same rule as incrementOrDecrement(), for the incrementEach and
     * decrementEach variants.
     */
    protected function incrementOrDecrementEach(array $columns, array $extra, string $method): never
    {
        throw InvalidProductLine::arithmeticNotAllowed(array_key_first($columns) ?? 'quantity');
    }

    /**
     * Registers the line invariants on creating and updating, so each Eloquent
     * write path (create, save, update) validates exactly once; increment and
     * decrement are rejected outright. A line must belong to an invoice, so it is
     * created through Invoice::productLines(). Query-builder writes bypass model
     * events by Eloquent design, and the schema is fixed by the task.
     */
    protected static function booted(): void
    {
        self::creating(static function (InvoiceProductLine $line): void {
            $invoiceId = $line->getAttributes()['invoice_id'] ?? null;

            if ($invoiceId === null || $invoiceId === '') {
                throw InvalidProductLine::withoutInvoice();
            }

            self::assertValidAmounts($line);
        });
        self::updating(static fn (InvoiceProductLine $line) => self::assertValidAmounts($line));
    }

    /**
     * Quantity and unit price must be integers between 1 and MAX_VALUE. The raw
     * attribute is validated, not the cast value, so 1.5 or '2widgets' cannot be
     * silently truncated. Valid values are normalised to int.
     */
    private static function assertValidAmounts(self $line): void
    {
        foreach (['quantity', 'unit_price'] as $field) {
            $raw = $line->getAttributes()[$field] ?? null;

            if (! is_int($raw) && ! is_string($raw)) {
                throw InvalidProductLine::notAnInteger($field, $raw);
            }

            $value = filter_var($raw, FILTER_VALIDATE_INT);

            if ($value === false) {
                throw InvalidProductLine::notAnInteger($field, $raw);
            }

            if ($value < 1) {
                throw InvalidProductLine::nonPositive($field, $value);
            }

            if ($value > self::MAX_VALUE) {
                throw InvalidProductLine::tooLarge($field, $value);
            }

            $line->setAttribute($field, $value);
        }
    }
}
