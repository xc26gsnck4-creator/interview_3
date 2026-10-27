<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoices\Domain\Models;

use Illuminate\Database\Eloquent\Factories\Attributes\UseModel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Models\Invoice;

/**
 * The namespace mirrors the model's so Laravel's default factory resolution finds
 * it without the model referencing the factory.
 *
 * inStatus(), sending() and sentToClient() are create()-only states: make() yields a draft,
 * because the model's creating hook rejects a new invoice in any other status,
 * so the states apply their status in afterCreating.
 *
 * @extends Factory<Invoice>
 */
#[UseModel(Invoice::class)]
final class InvoiceFactory extends Factory
{
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
        return $this->inStatus(StatusEnum::Sending);
    }

    public function sentToClient(): self
    {
        return $this->inStatus(StatusEnum::SentToClient);
    }

    /**
     * A test-only shortcut past the transitions.
     */
    public function inStatus(StatusEnum $status): self
    {
        return $this->afterCreating(static function (Invoice $invoice) use ($status): void {
            $invoice->forceFill(['status' => $status])->save();
        });
    }

    public function withLines(int $count = 1): self
    {
        return $this->has(InvoiceProductLineFactory::new()->count($count), 'productLines');
    }
}
