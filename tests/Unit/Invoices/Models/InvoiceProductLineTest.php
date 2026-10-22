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

        $fresh = $line->fresh();
        $this->assertSame(1, $fresh?->quantity);
        $this->assertSame(1, $fresh->unit_price);
        $this->assertDatabaseCount('invoice_product_lines', 1);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonPositiveValues(): array
    {
        return ['zero' => [0], 'negative' => [-1]];
    }

    #[DataProvider('nonPositiveValues')]
    public function test_rejects_non_positive_quantity(int $quantity): void
    {
        $this->expectException(InvalidProductLine::class);

        try {
            InvoiceProductLine::factory()->create(['quantity' => $quantity]);
        } finally {
            $this->assertDatabaseCount('invoice_product_lines', 0);
        }
    }

    #[DataProvider('nonPositiveValues')]
    public function test_rejects_non_positive_unit_price(int $unitPrice): void
    {
        $this->expectException(InvalidProductLine::class);

        try {
            InvoiceProductLine::factory()->create(['unit_price' => $unitPrice]);
        } finally {
            $this->assertDatabaseCount('invoice_product_lines', 0);
        }
    }
}
