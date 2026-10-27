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
     * @return Iterator<string, array{'quantity'|'unit_price', int}>
     */
    public static function nonPositiveValues(): Iterator
    {
        yield 'quantity zero' => ['quantity', 0];
        yield 'quantity negative' => ['quantity', -1];
        yield 'unit price zero' => ['unit_price', 0];
        yield 'unit price negative' => ['unit_price', -1];
    }

    /**
     * @param  'quantity'|'unit_price'  $field
     */
    #[DataProvider('nonPositiveValues')]
    public function test_rejects_non_positive_values(string $field, int $value): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::factory()->create([$field => $value]),
            InvalidProductLine::class,
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    public function test_rejects_a_null_amount(): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::factory()->create(['unit_price' => null]),
            InvalidProductLine::class,
            'Product line unit_price must be an integer between 1 and 2147483647, NULL given.',
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    /**
     * @return Iterator<string, array{'quantity'|'unit_price'}>
     */
    public static function bothFields(): Iterator
    {
        yield 'quantity' => ['quantity'];
        yield 'unit price' => ['unit_price'];
    }

    /**
     * @param  'quantity'|'unit_price'  $field
     */
    #[DataProvider('bothFields')]
    public function test_rejects_values_above_max_value(string $field): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::factory()->create([$field => InvoiceProductLine::MAX_VALUE + 1]),
            InvalidProductLine::class,
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    public function test_rejects_non_positive_values_on_update(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 2]);

        $this->assertThrows(fn () => $line->update(['quantity' => 0]), InvalidProductLine::class);
        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 2]);
    }
}
