<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoices\Domain\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Models\Invoice;
use Override;

/**
 * The namespace mirrors the model's so Laravel's default factory resolution finds
 * it without the model referencing the factory.
 *
 * sending() and sentToClient() are create()-only states: make() yields a draft,
 * because the model's creating invariant forbids constructing a non-draft invoice,
 * so the states apply their status in afterCreating.
 *
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    #[Override]
    protected $model = Invoice::class;

    /**
     * @return array<model-property<Invoice>, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_name' => fake()->name(),
            'customer_email' => fake()->safeEmail(),
        ];
    }

    public function sending(): self
    {
        return $this->afterCreating(static function (Invoice $invoice): void {
            self::forceStatus($invoice, StatusEnum::Sending);
        });
    }

    public function sentToClient(): self
    {
        return $this->afterCreating(static function (Invoice $invoice): void {
            self::forceStatus($invoice, StatusEnum::SentToClient);
        });
    }

    public function withLines(int $count = 1): self
    {
        return $this->has(InvoiceProductLineFactory::new()->count($count), 'productLines');
    }

    /**
     * Explicit test-only bypass of the creation invariant: invoices are created
     * as draft and then moved to the requested status without model events.
     */
    private static function forceStatus(Invoice $invoice, StatusEnum $status): void
    {
        $invoice->forceFill(['status' => $status])->saveQuietly();
    }
}
