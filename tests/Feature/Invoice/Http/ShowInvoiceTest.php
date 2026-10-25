<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice\Http;

use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
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
                ->has('product_lines', 2)
                ->has('product_lines.0', static fn (AssertableJson $line): AssertableJson => $line
                    ->where('id', $consulting->id)
                    ->where('name', 'Consulting')
                    ->where('quantity', 2)
                    ->where('unit_price', 15000)
                    ->where('total_unit_price', 30000))
                ->has('product_lines.1', static fn (AssertableJson $line): AssertableJson => $line
                    ->where('id', $hosting->id)
                    ->where('name', 'Hosting')
                    ->where('quantity', 3)
                    ->where('unit_price', 100)
                    ->where('total_unit_price', 300))
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

    public function test_returns_404_for_unknown_invoice(): void
    {
        $unknownId = (string) Str::uuid7();

        $response = $this->getJson(route('invoices.show', $unknownId));

        $response->assertNotFound()->assertExactJson(['message' => sprintf('Invoice %s was not found.', $unknownId)]);
        $this->assertMatchesContract($response, 404);
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
