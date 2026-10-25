<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice\Http;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Tests\Concerns\ValidatesOpenApiContract;
use Tests\TestCase;

final class ShowInvoiceTest extends TestCase
{
    use ValidatesOpenApiContract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommittedOpenApiDocument();
    }

    public function test_shows_invoice_with_lines_and_totals(): void
    {
        $invoice = Invoice::factory()->sending()->create(['customer_name' => 'Jane Doe', 'customer_email' => 'jane@example.com']);
        $consulting = InvoiceProductLine::factory()->for($invoice)->create(['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 15000]);
        $hosting = InvoiceProductLine::factory()->for($invoice)->create(['name' => 'Hosting', 'quantity' => 3, 'unit_price' => 100]);

        $response = $this->getJson(route('invoices.show', $invoice->id));

        $response->assertOk()->assertJson(static fn (AssertableJson $json): AssertableJson => $json
            ->has('data', static fn (AssertableJson $data): AssertableJson => $data
                ->where('id', $invoice->id)
                ->where('status', 'sending')
                ->where('customer_name', 'Jane Doe')
                ->where('customer_email', 'jane@example.com')
                ->where('product_lines', [
                    ['id' => $consulting->id, 'name' => 'Consulting', 'quantity' => 2, 'unit_price' => 15000, 'total_unit_price' => 30000],
                    ['id' => $hosting->id, 'name' => 'Hosting', 'quantity' => 3, 'unit_price' => 100, 'total_unit_price' => 300],
                ])
                ->where('total_price', 30300)));
        $this->assertMatchesContract($response, 200);
    }

    /**
     * The route accepts an uppercase uuid; ids are stored lowercase.
     */
    public function test_shows_invoice_by_uppercase_id(): void
    {
        $invoice = Invoice::factory()->withLines()->create();

        $response = $this->getJson(route('invoices.show', strtoupper($invoice->id)));

        $response->assertOk()->assertJsonPath('data.id', $invoice->id);
        $this->assertMatchesContract($response, 200);
    }

    /**
     * A client error, so it is not reported (logged with its trace).
     */
    public function test_returns_404_for_unknown_invoice(): void
    {
        $exceptions = Exceptions::fake();
        $unknownId = (string) Str::uuid7();

        $response = $this->getJson(route('invoices.show', $unknownId));

        $response->assertNotFound()->assertExactJson(['message' => sprintf('Invoice %s was not found.', $unknownId)]);
        $exceptions->assertNotReported(InvoiceNotFound::class);
        $this->assertMatchesContract($response, 404);
    }

    /**
     * Create rejects lines whose total overflows, so a stored invoice can only
     * overflow through a write that bypassed CreateInvoiceService. That is
     * corrupt data: a reported server error, not a client error, and
     * deliberately not part of the API document.
     */
    public function test_reports_a_stored_total_that_overflows_as_a_server_error(): void
    {
        $exceptions = Exceptions::fake();
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->count(3)->for($invoice)->create([
            'quantity' => InvoiceProductLine::MAX_VALUE,
            'unit_price' => InvoiceProductLine::MAX_VALUE,
        ]);

        $response = $this->getJson(route('invoices.show', $invoice->id));

        $response->assertInternalServerError();

        $exceptions->assertReported(InvoiceTotalOverflow::class);
    }

    /**
     * No route matches, so there is no operation to check the response
     * against. Without an Accept header Laravel would answer an HTML page.
     * With APP_DEBUG the body also carries the exception and its trace.
     */
    public function test_returns_json_404_for_non_uuid_id_without_an_accept_header(): void
    {
        $response = $this->get('/api/invoices/not-a-uuid');

        $response->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', 'The route api/invoices/not-a-uuid could not be found.');
    }
}
