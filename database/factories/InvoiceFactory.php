<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<model-property<Invoice>, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
            'status' => StatusEnum::Draft,
        ];
    }

    public function sending(): self
    {
        return $this->afterCreating(static fn (Invoice $invoice): bool => self::forceStatus($invoice, StatusEnum::Sending));
    }

    public function sentToClient(): self
    {
        return $this->afterCreating(static fn (Invoice $invoice): bool => self::forceStatus($invoice, StatusEnum::SentToClient));
    }

    public function withLines(int $count = 1): self
    {
        return $this->has(InvoiceProductLineFactory::new()->count($count), 'productLines');
    }

    /**
     * Explicit test-only bypass of the creation invariant: invoices are created
     * as draft and then moved to the requested status without model events.
     */
    private static function forceStatus(Invoice $invoice, StatusEnum $status): bool
    {
        return $invoice->forceFill(['status' => $status])->saveQuietly();
    }
}
