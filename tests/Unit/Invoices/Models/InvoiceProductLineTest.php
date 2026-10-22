<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Models;

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
}
