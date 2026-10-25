<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice\Http;

use Illuminate\Support\Arr;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ValidatesOpenApiContract;
use Tests\TestCase;

final class CreateInvoiceTest extends TestCase
{
    use ValidatesOpenApiContract;

    private const array LINE = ['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 15000];

    private const array PAYLOAD = [
        'customer_name' => 'Jane Doe',
        'customer_email' => 'jane@example.com',
        'product_lines' => [self::LINE],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommittedOpenApiDocument();
    }

    public function test_creates_invoice_with_lines_and_returns_201(): void
    {
        $response = $this->postJson(route('invoices.store'), self::PAYLOAD);

        $response->assertCreated()->assertJson(static fn (AssertableJson $json): AssertableJson => $json
            ->has('data', static fn (AssertableJson $data): AssertableJson => $data
                ->whereType('id', 'string')
                ->where('status', 'draft')
                ->where('customer_name', 'Jane Doe')
                ->where('customer_email', 'jane@example.com')
                ->has('product_lines', 1, static fn (AssertableJson $line): AssertableJson => $line
                    ->whereType('id', 'string')
                    ->where('name', 'Consulting')
                    ->where('quantity', 2)
                    ->where('unit_price', 15000)
                    ->where('total_unit_price', 30000))
                ->where('total_price', 30000)));

        $invoiceId = $response->json('data.id');
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId,
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('invoice_product_lines', [
            'id' => $response->json('data.product_lines.0.id'),
            'invoice_id' => $invoiceId,
            'name' => 'Consulting',
            'quantity' => 2,
            'unit_price' => 15000,
        ]);
        $this->assertMatchesContract($response, 201);
    }

    public function test_creates_invoice_with_empty_lines_array(): void
    {
        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'product_lines' => []]);

        $this->assertCreatedWithoutLines($response);
    }

    public function test_creates_invoice_when_lines_key_is_absent(): void
    {
        $response = $this->postJson(route('invoices.store'), Arr::except(self::PAYLOAD, ['product_lines']));

        $this->assertCreatedWithoutLines($response);
    }

    /**
     * @return Iterator<string, array{int, int, int}>
     */
    public static function lineAmountsAtTheBounds(): Iterator
    {
        yield 'minimum' => [1, 1, 1];
        yield 'maximum' => [2147483647, 2147483647, 4611686014132420609];
    }

    #[DataProvider('lineAmountsAtTheBounds')]
    public function test_accepts_line_amounts_at_the_bounds(int $quantity, int $unitPrice, int $total): void
    {
        $response = $this->postJson(route('invoices.store'), self::withLine(['name' => 'Bulk', 'quantity' => $quantity, 'unit_price' => $unitPrice]));

        $response->assertCreated()
            ->assertJsonPath('data.product_lines.0.total_unit_price', $total)
            ->assertJsonPath('data.total_price', $total);
        $this->assertDatabaseHas('invoice_product_lines', ['name' => 'Bulk', 'quantity' => $quantity, 'unit_price' => $unitPrice]);
        $this->assertMatchesContract($response, 201);
    }

    public function test_accepts_the_maximum_of_1000_lines(): void
    {
        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'product_lines' => array_fill(0, 1000, self::LINE)]);

        $response->assertCreated()->assertJsonCount(1000, 'data.product_lines');
        $this->assertDatabaseCount('invoice_product_lines', 1000);
        $this->assertMatchesContract($response, 201);
    }

    public function test_ignores_status_in_payload(): void
    {
        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'status' => 'sent-to-client']);

        $response->assertCreated()->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('invoices', ['id' => $response->json('data.id'), 'status' => 'draft']);
        $this->assertMatchesContract($response, 201);
    }

    /**
     * @return Iterator<string, array{array<mixed>, string}>
     */
    public static function invalidPayloads(): Iterator
    {
        yield 'missing customer name' => [Arr::except(self::PAYLOAD, ['customer_name']), 'customer_name'];
        yield 'missing customer email' => [Arr::except(self::PAYLOAD, ['customer_email']), 'customer_email'];
        yield 'malformed customer email' => [[...self::PAYLOAD, 'customer_email' => 'nope'], 'customer_email'];
        yield 'customer name of 256 characters' => [[...self::PAYLOAD, 'customer_name' => str_repeat('n', 256)], 'customer_name'];
        yield 'customer email of 256 characters' => [[...self::PAYLOAD, 'customer_email' => self::validEmailOf256Characters()], 'customer_email'];
        yield 'product lines as a string' => [[...self::PAYLOAD, 'product_lines' => 'x'], 'product_lines'];
        yield 'product lines as an object' => [[...self::PAYLOAD, 'product_lines' => ['first' => self::LINE]], 'product_lines'];
        yield '1001 product lines' => [[...self::PAYLOAD, 'product_lines' => array_fill(0, 1001, self::LINE)], 'product_lines'];
        yield 'empty line name' => [self::withLine([...self::LINE, 'name' => '']), 'product_lines.0.name'];
        yield 'blank line name' => [self::withLine([...self::LINE, 'name' => '   ']), 'product_lines.0.name'];
        yield 'line name of 256 characters' => [self::withLine([...self::LINE, 'name' => str_repeat('n', 256)]), 'product_lines.0.name'];
        yield 'line that is not an object' => [[...self::PAYLOAD, 'product_lines' => ['Consulting']], 'product_lines.0.name'];
        yield 'line without name' => [self::withLine(Arr::except(self::LINE, ['name'])), 'product_lines.0.name'];
        yield 'line without quantity' => [self::withLine(Arr::except(self::LINE, ['quantity'])), 'product_lines.0.quantity'];
        yield 'line without unit price' => [self::withLine(Arr::except(self::LINE, ['unit_price'])), 'product_lines.0.unit_price'];
        yield 'zero quantity' => [self::withLine([...self::LINE, 'quantity' => 0]), 'product_lines.0.quantity'];
        yield 'fractional quantity' => [self::withLine([...self::LINE, 'quantity' => 1.5]), 'product_lines.0.quantity'];
        yield 'boolean quantity' => [self::withLine([...self::LINE, 'quantity' => true]), 'product_lines.0.quantity'];
        yield 'quantity as a string' => [self::withLine([...self::LINE, 'quantity' => '2']), 'product_lines.0.quantity'];
        yield 'quantity above the maximum' => [self::withLine([...self::LINE, 'quantity' => 2147483648]), 'product_lines.0.quantity'];
        yield 'negative unit price' => [self::withLine([...self::LINE, 'unit_price' => -5]), 'product_lines.0.unit_price'];
        yield 'unit price as a string' => [self::withLine([...self::LINE, 'unit_price' => '2']), 'product_lines.0.unit_price'];
        yield 'unit price above the maximum' => [self::withLine([...self::LINE, 'unit_price' => 2147483648]), 'product_lines.0.unit_price'];
    }

    /**
     * @param  array<mixed>  $payload
     */
    #[DataProvider('invalidPayloads')]
    public function test_rejects_invalid_payload_with_422(array $payload, string $errorKey): void
    {
        $response = $this->postJson(route('invoices.store'), $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$errorKey]);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * Each line is valid on its own; their total does not fit PHP's int, so the
     * invoice is rolled back and the client gets a validation error.
     */
    public function test_rejects_lines_whose_total_overflows_with_422(): void
    {
        $line = ['name' => 'Bulk', 'quantity' => 2147483647, 'unit_price' => 2147483647];

        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'product_lines' => [$line, $line, $line]]);

        $response->assertUnprocessable()->assertExactJson([
            'message' => 'The total of the product lines must not exceed 9223372036854775807.',
            'errors' => ['product_lines' => ['The total of the product lines must not exceed 9223372036854775807.']],
        ]);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('invoice_product_lines', 0);
        $this->assertMatchesContract($response, 422);
    }

    /**
     * The API takes JSON only: a form body is not read at all, so bytes that
     * are not valid UTF-8 (which the response could not encode) never reach
     * the database.
     */
    public function test_ignores_a_form_body_and_answers_422(): void
    {
        $response = $this->post(route('invoices.store'), ['customer_name' => "\xFF", 'customer_email' => 'jane@example.com']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_name', 'customer_email']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    public function test_treats_a_malformed_json_body_as_empty_and_answers_422(): void
    {
        $response = $this->call(
            'POST',
            route('invoices.store', ['customer_name' => 'Jane Doe', 'customer_email' => 'jane@example.com']),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: '{"product_lines":[',
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_name', 'customer_email']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * Laravel trims strings and turns empty ones into null only in a request
     * marked as JSON; read without that, `""` for product_lines slipped past
     * the array rules and "  Jane  " was stored untrimmed.
     */
    public function test_does_not_read_a_body_without_a_json_content_type(): void
    {
        $response = $this->call(
            'POST',
            route('invoices.store'),
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            content: (string) json_encode([...self::PAYLOAD, 'customer_name' => '  Jane  ', 'product_lines' => '']),
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_name', 'customer_email']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    public function test_ignores_fields_in_the_query_string(): void
    {
        $response = $this->postJson(
            route('invoices.store', ['customer_email' => 'jane@example.com']),
            Arr::except(self::PAYLOAD, ['customer_email']),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_email'])
            ->assertJsonMissingValidationErrors(['customer_name']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * curl sends `Accept: *\/*`; without an explicit JSON Accept header Laravel
     * would redirect a failed validation to the previous URL.
     */
    public function test_returns_json_validation_errors_without_an_accept_header(): void
    {
        $response = $this->post(route('invoices.store'));

        $response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['customer_name', 'customer_email']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * @param  array<mixed>  $line
     * @return array<string, mixed>
     */
    private static function withLine(array $line): array
    {
        return [...self::PAYLOAD, 'product_lines' => [$line]];
    }

    /**
     * A well-formed address the `email` rule accepts on its own, so only the
     * length limit rejects it.
     */
    private static function validEmailOf256Characters(): string
    {
        return str_repeat('a', 8).'@'.implode('.', array_fill(0, 4, str_repeat('b', 60))).'.com';
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function assertCreatedWithoutLines(TestResponse $response): void
    {
        $response->assertCreated()
            ->assertJsonPath('data.product_lines', [])
            ->assertJsonPath('data.total_price', 0);
        $this->assertDatabaseHas('invoices', ['id' => $response->json('data.id'), 'status' => 'draft']);
        $this->assertDatabaseCount('invoice_product_lines', 0);
        $this->assertMatchesContract($response, 201);
    }
}
