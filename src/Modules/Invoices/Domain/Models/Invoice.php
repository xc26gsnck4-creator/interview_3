<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\Models;

use Database\Factories\Modules\Invoices\Domain\Models\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvoiceMustBeCreatedAsDraft;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;

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
     * the creating hook pins draft, and the status transitions are the sanctioned
     * way to change it.
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

    /**
     * A single line total fits in int because of InvoiceProductLine::MAX_VALUE;
     * the sum across lines may not, so the addition is checked.
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['status' => StatusEnum::class];
    }

    /**
     * Mass assignment cannot set status; this hook closes the direct-assignment
     * path ($invoice->status = ...; $invoice->save()), so an invoice can only
     * ever be created as draft.
     */
    protected static function booted(): void
    {
        self::creating(static function (Invoice $invoice): void {
            if ($invoice->status !== StatusEnum::Draft) {
                throw InvoiceMustBeCreatedAsDraft::withStatus($invoice->status);
            }
        });
    }
}
