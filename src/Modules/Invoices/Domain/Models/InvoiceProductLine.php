<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\InvoiceProductLineFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Invoices\Domain\Exceptions\InvalidProductLine;

/**
 * @property string $id
 * @property string $invoice_id
 * @property string $name
 * @property int $quantity
 * @property int $unit_price
 */
final class InvoiceProductLine extends Model
{
    /** @use HasFactory<InvoiceProductLineFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = ['name', 'quantity', 'unit_price'];

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
     * Enforces the line invariant on every insert and update: quantity and
     * unit price must both be at least 1.
     */
    protected static function booted(): void
    {
        self::saving(static function (InvoiceProductLine $line): void {
            $values = ['quantity' => $line->quantity, 'unit_price' => $line->unit_price];

            foreach ($values as $field => $value) {
                if ($value < 1) {
                    throw InvalidProductLine::nonPositive($field, $value);
                }
            }
        });
    }

    protected static function newFactory(): InvoiceProductLineFactory
    {
        return InvoiceProductLineFactory::new();
    }
}
