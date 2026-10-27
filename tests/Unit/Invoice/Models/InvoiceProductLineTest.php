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
        self::assertSame(1, $fresh->quantity);
        self::assertSame(1, $fresh->unit_price);
    }

    /**
     * @return Iterator<string, array{'quantity'|'unit_price', int|float|string|null, string}>
     */
    public static function invalidAmounts(): Iterator
    {
        yield 'quantity zero' => ['quantity', 0, '0'];
        yield 'quantity negative' => ['quantity', -1, '-1'];
        yield 'quantity above MAX_VALUE' => ['quantity', InvoiceProductLine::MAX_VALUE + 1, '2147483648'];
        yield 'quantity fractional' => ['quantity', 1.5, '1.5'];
        yield 'unit price zero' => ['unit_price', 0, '0'];
        yield 'unit price negative' => ['unit_price', -1, '-1'];
        yield 'unit price above MAX_VALUE' => ['unit_price', InvoiceProductLine::MAX_VALUE + 1, '2147483648'];
        yield 'unit price as text' => ['unit_price', '2', "'2'"];
        yield 'unit price null' => ['unit_price', null, 'NULL'];
    }

    /**
     * @param  'quantity'|'unit_price'  $field
     */
    #[DataProvider('invalidAmounts')]
    public function test_rejects_amounts_that_are_not_integers_in_range(string $field, int|float|string|null $value, string $renderedValue): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::factory()->create([$field => $value]),
            InvalidProductLine::class,
            sprintf('Product line %s must be an integer between 1 and 2147483647, %s given.', $field, $renderedValue),
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    /**
     * The fraction keeps the stored integer part: Eloquent's dirty comparison
     * reads both through the cast and sees no change, so only a check that
     * runs before it, on saving, can reject the value.
     *
     * @return Iterator<string, array{int, int|float}>
     */
    public static function invalidAmountsOnUpdate(): Iterator
    {
        yield 'zero' => [2, 0];
        yield 'fraction with the stored integer part' => [1, 1.5];
    }

    #[DataProvider('invalidAmountsOnUpdate')]
    public function test_rejects_invalid_amounts_on_update(int $stored, int|float $value): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => $stored]);

        $this->assertThrows(fn () => $line->update(['quantity' => $value]), InvalidProductLine::class);
        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => $stored]);
    }

    /**
     * decrement() fires updating only, never saving, so the check must be
     * registered there too.
     */
    public function test_rejects_a_decrement_below_one(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 1]);

        $this->assertThrows(fn () => $line->decrement('quantity'), InvalidProductLine::class);
        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 1]);
    }
}
