<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Models;

use Modules\Invoices\Domain\Exceptions\InvalidProductLine;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class InvoiceProductLineTest extends TestCase
{
    public function test_total_unit_price_is_quantity_times_unit_price(): void
    {
        $line = InvoiceProductLine::factory()->make(['quantity' => 3, 'unit_price' => 250]);

        $this->assertSame(750, $line->totalUnitPrice());
    }

    public function test_minimum_line_is_accepted(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 1, 'unit_price' => 1]);

        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 1, 'unit_price' => 1]);
        $this->assertDatabaseCount('invoice_product_lines', 1);

        $fresh = InvoiceProductLine::query()->findOrFail($line->id);
        $this->assertSame(1, $fresh->quantity);
        $this->assertSame(1, $fresh->unit_price);
    }

    /**
     * @return array<string, array{'quantity'|'unit_price', int}>
     */
    public static function nonPositiveValues(): array
    {
        return [
            'quantity zero' => ['quantity', 0],
            'quantity negative' => ['quantity', -1],
            'unit price zero' => ['unit_price', 0],
            'unit price negative' => ['unit_price', -1],
        ];
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

    /**
     * @return array<string, array{'quantity'|'unit_price', mixed}>
     */
    public static function nonIntegerValues(): array
    {
        return [
            'fractional quantity' => ['quantity', 1.5],
            'garbage quantity' => ['quantity', '2widgets'],
            'null unit price' => ['unit_price', null],
            'boolean quantity' => ['quantity', true],
            'float unit price' => ['unit_price', 1.0],
        ];
    }

    /**
     * @param  'quantity'|'unit_price'  $field
     */
    #[DataProvider('nonIntegerValues')]
    public function test_rejects_non_integer_values(string $field, mixed $value): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::factory()->create([$field => $value]),
            InvalidProductLine::class,
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    /**
     * @return array<string, array{'quantity'|'unit_price'}>
     */
    public static function bothFields(): array
    {
        return ['quantity' => ['quantity'], 'unit price' => ['unit_price']];
    }

    /**
     * @param  'quantity'|'unit_price'  $field
     */
    #[DataProvider('bothFields')]
    public function test_rejects_values_above_the_integer_column_range(string $field): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::factory()->create([$field => 2_147_483_648]),
            InvalidProductLine::class,
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    public function test_normalises_numeric_strings(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => '3', 'unit_price' => '250']);

        $fresh = InvoiceProductLine::query()->findOrFail($line->id);
        $this->assertSame(3, $fresh->quantity);
        $this->assertSame(250, $fresh->unit_price);
        $this->assertSame(750, $fresh->totalUnitPrice());
    }

    public function test_rejects_non_positive_values_on_update(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 2]);

        $this->assertThrows(fn () => $line->update(['quantity' => 0]), InvalidProductLine::class);
        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 2]);
    }

    public function test_rejects_decrement_below_one(): void
    {
        $line = InvoiceProductLine::factory()->create(['quantity' => 1]);

        $this->assertThrows(fn () => $line->decrement('quantity'), InvalidProductLine::class);
        $this->assertDatabaseHas('invoice_product_lines', ['id' => $line->id, 'quantity' => 1]);
    }

    public function test_line_cannot_be_created_without_an_invoice(): void
    {
        $this->assertThrows(
            fn () => InvoiceProductLine::create(['name' => 'x', 'quantity' => 1, 'unit_price' => 1]),
            InvalidProductLine::class,
        );
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }
}
