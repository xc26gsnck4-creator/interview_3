<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Models;

use Iterator;
use Modules\Invoices\Domain\Exceptions\InvalidProductLine;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InvoiceProductLineTest extends TestCase
{
    public function test_total_unit_price_is_quantity_times_unit_price(): void
    {
        $line = InvoiceProductLine::factory()->make(['quantity' => 3, 'unit_price' => 250]);

        self::assertSame(750, $line->totalUnitPrice());
    }

    public function test_minimum_line_is_accepted(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 1, 'unit_price' => 1]);

        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 1, 'unit_price' => 1]);
        $this->assertDatabaseCount('invoice_product_lines', 1);

        $fresh = InvoiceProductLine::query()->findOrFail($line->id);
        self::assertSame(1, $fresh->quantity->value);
        self::assertSame(1, $fresh->unit_price->value);
    }

    /**
     * The bounds live in AmountTest; these two pin that the cast checks the
     * value as given, before any integer conversion.
     *
     * @return Iterator<string, array{'quantity'|'unit_price', float|string, string}>
     */
    public static function invalidAmounts(): Iterator
    {
        yield 'quantity fractional' => ['quantity', 1.5, '1.5'];
        yield 'unit price as text' => ['unit_price', '2', "'2'"];
    }

    /**
     * @param  'quantity'|'unit_price'  $field
     */
    #[DataProvider('invalidAmounts')]
    public function test_rejects_an_amount_that_is_not_an_integer_as_given(string $field, float|string $value, string $renderedValue): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::factory()->create([$field => $value]),
            InvalidProductLine::class,
            sprintf('Product line %s must be an integer between 1 and 2147483647, %s given.', $field, $renderedValue),
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    /**
     * The cast reads what the driver returns, so a loaded line can be saved
     * again.
     */
    public function test_updates_a_valid_amount(): void
    {
        $line = InvoiceProductLine::query()->findOrFail(InvoiceProductLine::factory()->create(['quantity' => 2])->id);

        $line->update(['quantity' => 3]);

        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 3]);
    }

    /**
     * 1.5 over a stored 1 is the update the integer cast once dropped as
     * unchanged; the cast rejects it at assignment, before Eloquent compares
     * anything.
     */
    public function test_rejects_a_fraction_on_update(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 1]);

        $this->assertThrows(fn () => $line->update(['quantity' => 1.5]), InvalidProductLine::class);
        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 1]);
    }

    /**
     * decrement() computes the new amount through the cast, so a result below
     * one is rejected like any other assignment.
     */
    public function test_rejects_a_decrement_below_one(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 1]);

        $this->assertThrows(fn () => $line->decrement('quantity'), InvalidProductLine::class);
        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 1]);
    }
}
