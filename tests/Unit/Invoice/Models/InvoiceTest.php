<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Models;

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvoiceMustBeCreatedAsDraft;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Tests\TestCase;

final class InvoiceTest extends TestCase
{
    public function test_status_passed_on_create_throws_while_models_are_strict(): void
    {
        $this->assertThrows(
            fn () => Invoice::query()->create([
                'customer_name' => 'Acme',
                'customer_email' => 'billing@acme.test',
                'status' => 'sending',
            ]),
            MassAssignmentException::class,
            'Add fillable property [status] to allow mass assignment on ['.Invoice::class.'].',
        );
        $this->assertDatabaseCount('invoices', 0);
    }

    /**
     * Production models are not strict (AppServiceProvider), so there the
     * non-fillable status is discarded rather than rejected.
     */
    public function test_new_invoice_is_draft_even_when_status_is_passed(): void
    {
        Model::preventSilentlyDiscardingAttributes(false);

        try {
            $invoice = Invoice::query()->create([
                'customer_name' => 'Acme',
                'customer_email' => 'billing@acme.test',
                'status' => 'sending',
            ]);
        } finally {
            Model::preventSilentlyDiscardingAttributes();
        }

        self::assertSame(StatusEnum::Draft, $invoice->fresh()?->status);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    public function test_invoice_without_lines_has_zero_total(): void
    {
        $invoice = Invoice::factory()->create();

        self::assertSame(0, $invoice->totalPrice());
    }

    public function test_total_price_sums_line_totals(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->for($invoice)->create(['quantity' => 2, 'unit_price' => 1500]);
        InvoiceProductLine::factory()->for($invoice)->create(['quantity' => 3, 'unit_price' => 100]);

        self::assertSame(3300, $invoice->totalPrice());
    }

    public function test_factory_states_persist_their_status(): void
    {
        $sending = Invoice::factory()->sending()->create();
        $sent = Invoice::factory()->sentToClient()->create();

        self::assertSame(StatusEnum::Sending, $sending->fresh()?->status);
        self::assertSame(StatusEnum::SentToClient, $sent->fresh()?->status);
    }

    public function test_with_lines_attaches_the_requested_number_of_lines(): void
    {
        $invoice = Invoice::factory()->withLines(3)->create();

        self::assertCount(3, $invoice->productLines);
        $this->assertDatabaseCount('invoice_product_lines', 3);
    }

    public function test_new_invoice_cannot_be_saved_with_a_non_draft_status(): void
    {
        $invoice = new Invoice(['customer_name' => 'Acme', 'customer_email' => 'billing@acme.test']);
        $invoice->status = StatusEnum::Sending;

        $this->assertThrows(
            fn () => $invoice->save(),
            InvoiceMustBeCreatedAsDraft::class,
            'An invoice must be created as draft, sending given.',
        );
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_factory_states_combine_with_lines(): void
    {
        $invoice = Invoice::factory()->sending()->withLines(2)->create();

        self::assertSame(StatusEnum::Sending, $invoice->fresh()?->status);
        $this->assertDatabaseCount('invoice_product_lines', 2);
    }

    public function test_new_invoice_cannot_be_saved_with_a_null_status(): void
    {
        $invoice = new Invoice(['customer_name' => 'Acme', 'customer_email' => 'billing@acme.test']);
        $invoice->setAttribute('status', null);

        $this->assertThrows(
            fn () => $invoice->save(),
            InvoiceMustBeCreatedAsDraft::class,
            'An invoice must be created as draft, none given.',
        );
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_total_price_throws_when_the_sum_overflows(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->count(3)->for($invoice)->create([
            'quantity' => InvoiceProductLine::MAX_VALUE,
            'unit_price' => InvoiceProductLine::MAX_VALUE,
        ]);

        $this->assertThrows(
            fn () => $invoice->totalPrice(),
            InvoiceTotalOverflow::class,
            sprintf('The total of invoice %s exceeds the maximum representable integer.', $invoice->id),
        );
    }

    /**
     * With MAX = 2^31 - 1: MAX^2 + (MAX - 1)^2 + 6 * MAX = 2(MAX + 1)^2 - 1
     * = 2^63 - 1 = PHP_INT_MAX, which is still representable.
     */
    public function test_total_price_accepts_a_sum_of_exactly_php_int_max(): void
    {
        $max = InvoiceProductLine::MAX_VALUE;
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->for($invoice)->forEachSequence(
            ['quantity' => $max, 'unit_price' => $max],
            ['quantity' => $max - 1, 'unit_price' => $max - 1],
            ['quantity' => 6, 'unit_price' => $max],
        )->create();

        self::assertSame(PHP_INT_MAX, $invoice->totalPrice());
    }

    public function test_total_price_includes_lines_added_after_the_relation_was_loaded(): void
    {
        $invoice = Invoice::factory()->create();
        self::assertCount(0, $invoice->productLines);

        $invoice->productLines()->create(['name' => 'x', 'quantity' => 2, 'unit_price' => 100]);

        self::assertSame(200, $invoice->totalPrice());
    }
}
