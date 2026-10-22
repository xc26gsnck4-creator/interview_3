<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Domain\Enums\StatusEnum;

/**
 * @property string $id
 * @property string $customer_name
 * @property string $customer_email
 * @property StatusEnum $status
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * Status is deliberately not fillable: mass assignment can never set it,
     * so a new invoice is always a draft. Only transitions change it.
     *
     * @var list<string>
     */
    protected $fillable = ['customer_name', 'customer_email'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => StatusEnum::Draft->value];

    /**
     * @return HasMany<InvoiceProductLine, $this>
     */
    public function productLines(): HasMany
    {
        return $this->hasMany(InvoiceProductLine::class);
    }

    public function totalPrice(): int
    {
        return $this->productLines->sum(
            static fn (InvoiceProductLine $line): int => $line->totalUnitPrice(),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => StatusEnum::class];
    }

    protected static function newFactory(): InvoiceFactory
    {
        return InvoiceFactory::new();
    }
}
