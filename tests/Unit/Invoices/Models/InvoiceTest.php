<?php

declare(strict_types=1);

namespace Tests\Unit\Invoices\Models;

use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    public function test_new_invoice_is_draft_even_when_status_is_passed(): void
    {
        $invoice = Invoice::create([
            'customer_name' => 'Acme',
            'customer_email' => 'billing@acme.test',
            'status' => 'sending',
        ]);

        $this->assertSame(StatusEnum::Draft, $invoice->fresh()?->status);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    public function test_invoice_without_lines_has_zero_total(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertSame(0, $invoice->totalPrice());
    }

    public function test_total_price_sums_line_totals(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->for($invoice)->create(['quantity' => 2, 'unit_price' => 1500]);
        InvoiceProductLine::factory()->for($invoice)->create(['quantity' => 3, 'unit_price' => 100]);

        $this->assertSame(3300, $invoice->totalPrice());
    }

    public function test_factory_states_persist_their_status(): void
    {
        $sending = Invoice::factory()->sending()->create();
        $sent = Invoice::factory()->sentToClient()->create();

        $this->assertSame(StatusEnum::Sending, $sending->fresh()?->status);
        $this->assertSame(StatusEnum::SentToClient, $sent->fresh()?->status);
    }

    public function test_with_lines_attaches_the_requested_number_of_lines(): void
    {
        $invoice = Invoice::factory()->withLines(3)->create();

        $this->assertCount(3, $invoice->productLines);
        $this->assertDatabaseCount('invoice_product_lines', 3);
    }
}
