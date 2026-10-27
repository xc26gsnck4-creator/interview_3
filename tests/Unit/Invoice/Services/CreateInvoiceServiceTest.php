<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Services;

use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Application\Services\CreateInvoiceService;
use Modules\Invoices\Domain\Enums\InvoiceStatus;
use Modules\Invoices\Domain\Exceptions\InvalidProductLine;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Tests\TestCase;

final class CreateInvoiceServiceTest extends TestCase
{
    public function test_creates_draft_invoice_with_lines(): void
    {
        $invoice = $this->service()->handle(new CreateInvoiceData(
            customerName: 'Acme',
            customerEmail: 'billing@acme.test',
            productLines: [
                new ProductLineData(name: 'Consulting', quantity: 2, unitPrice: 1500),
                new ProductLineData(name: 'Hosting', quantity: 3, unitPrice: 100),
            ],
        ));

        self::assertSame(InvoiceStatus::Draft, $invoice->status);
        self::assertSame(3300, $invoice->totalPrice());
        self::assertTrue($invoice->relationLoaded('productLines'));
        self::assertSame(['Consulting', 'Hosting'], $invoice->productLines->pluck('name')->all());
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'customer_name' => 'Acme',
            'customer_email' => 'billing@acme.test',
            'status' => 'draft',
        ]);
        $this->assertDatabaseCount('invoice_product_lines', 2);
        $this->assertDatabaseHas('invoice_product_lines', [
            'invoice_id' => $invoice->id,
            'name' => 'Consulting',
            'quantity' => 2,
            'unit_price' => 1500,
        ]);
        $this->assertDatabaseHas('invoice_product_lines', [
            'invoice_id' => $invoice->id,
            'name' => 'Hosting',
            'quantity' => 3,
            'unit_price' => 100,
        ]);
    }

    public function test_creates_draft_invoice_without_lines(): void
    {
        $invoice = $this->service()->handle(new CreateInvoiceData(
            customerName: 'Acme',
            customerEmail: 'billing@acme.test',
        ));

        self::assertSame(InvoiceStatus::Draft, $invoice->status);
        self::assertSame(0, $invoice->totalPrice());
        self::assertTrue($invoice->relationLoaded('productLines'));
        self::assertCount(0, $invoice->productLines);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    public function test_persists_nothing_when_a_line_is_invalid(): void
    {
        $data = new CreateInvoiceData(
            customerName: 'Acme',
            customerEmail: 'billing@acme.test',
            productLines: [
                new ProductLineData(name: 'Consulting', quantity: 2, unitPrice: 1500),
                new ProductLineData(name: 'Broken', quantity: 0, unitPrice: 100),
            ],
        );

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($data),
            InvalidProductLine::class,
            'Product line quantity must be an integer between 1 and 2147483647, 0 given.',
        );
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    public function test_persists_nothing_when_the_total_overflows(): void
    {
        $line = new ProductLineData(name: 'Bulk', quantity: InvoiceProductLine::MAX_VALUE, unitPrice: InvoiceProductLine::MAX_VALUE);
        $data = new CreateInvoiceData(customerName: 'Acme', customerEmail: 'billing@acme.test', productLines: [$line, $line, $line]);

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($data),
            InvoiceTotalOverflow::class,
            'exceeds the maximum representable integer.',
        );
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_product_lines', 0);
    }

    private function service(): CreateInvoiceService
    {
        return $this->app->make(CreateInvoiceService::class);
    }
}
