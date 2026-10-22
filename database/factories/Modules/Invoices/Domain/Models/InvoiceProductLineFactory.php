<?php

declare(strict_types=1);

namespace Database\Factories\Modules\Invoices\Domain\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;

/**
 * The namespace mirrors the model's so Laravel's default factory resolution finds
 * it without the model referencing the factory.
 *
 * @extends Factory<InvoiceProductLine>
 */
final class InvoiceProductLineFactory extends Factory
{
    protected $model = InvoiceProductLine::class;

    /**
     * @return array<model-property<InvoiceProductLine>, mixed>
     */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'name' => fake()->words(3, true),
            'quantity' => fake()->numberBetween(1, 10),
            'unit_price' => fake()->numberBetween(100, 100000),
        ];
    }
}
