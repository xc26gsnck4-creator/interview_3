<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice\Http;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    public function test_runs_no_query_after_the_commit(): void
    {
        $committed = false;
        $queriesAfterCommit = [];
        Event::listen(TransactionCommitted::class, static function () use (&$committed): void {
            $committed = true;
        });
        DB::listen(static function (QueryExecuted $query) use (&$committed, &$queriesAfterCommit): void {
            if ($committed) {
                $queriesAfterCommit[] = $query->sql;
            }
        });

        $response = $this->postJson(route('invoices.store'), self::PAYLOAD);

        $response->assertCreated();
        self::assertTrue($committed);
        self::assertSame([], $queriesAfterCommit);
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

    /**
     * Laravel expands the per-line rules over every submitted line before any
     * rule runs, so the count is checked first: the lines of an oversized body
     * are never validated.
     */
    public function test_rejects_more_than_1000_lines_before_validating_them(): void
    {
        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'product_lines' => array_fill(0, 1001, ['name' => ''])]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['product_lines'])
            ->assertJsonMissingValidationErrors(['product_lines.0.name']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * The check that tells a JSON object from an array adds its error only for
     * a value the array rules accepted, so no message is reported twice.
     */
    public function test_reports_each_product_lines_error_once(): void
    {
        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'product_lines' => 'x']);

        $response->assertUnprocessable()->assertJsonPath('errors.product_lines', [
            'The product lines field must be an array.',
            'The product lines field must be a list.',
        ]);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    public function test_accepts_the_maximum_of_1000_lines(): void
    {
        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'product_lines' => array_fill(0, 1000, self::LINE)]);

        $response->assertCreated()->assertJsonCount(1000, 'data.product_lines');
        $this->assertDatabaseCount('invoice_product_lines', 1000);
        $this->assertMatchesContract($response, 201);
    }

    public function test_accepts_text_of_exactly_255_characters(): void
    {
        $name = str_repeat('n', 255);

        $response = $this->postJson(route('invoices.store'), [
            ...self::withLine([...self::LINE, 'name' => $name]),
            'customer_name' => $name,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_name', $name)
            ->assertJsonPath('data.product_lines.0.name', $name);
        $this->assertDatabaseHas('invoices', ['id' => $response->json('data.id'), 'customer_name' => $name]);
        $this->assertMatchesContract($response, 201);
    }

    /**
     * The document's `email` format caps an address at 254 characters (RFC 5321)
     * while the rule accepts 255, so the request leaves the document's format
     * (not its `maxLength`) and the contract check skips it.
     */
    public function test_accepts_an_email_of_exactly_255_characters(): void
    {
        $email = self::validEmailOfLength(255);

        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'customer_email' => $email]);

        $response->assertCreated()->assertJsonPath('data.customer_email', $email);
        $this->assertDatabaseHas('invoices', ['id' => $response->json('data.id'), 'customer_email' => $email]);
        $this->assertMatchesContract($response, 201, validRequest: false);
    }

    /**
     * The limit counts characters, as the document's `maxLength` does, not bytes.
     */
    public function test_accepts_255_multibyte_characters(): void
    {
        $name = str_repeat('ń', 255);

        $response = $this->postJson(route('invoices.store'), [
            ...self::withLine([...self::LINE, 'name' => $name]),
            'customer_name' => $name,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_name', $name)
            ->assertJsonPath('data.product_lines.0.name', $name);
        $this->assertMatchesContract($response, 201);
    }

    /**
     * Only what is left after trimming is stored and answered.
     */
    public function test_stores_and_answers_a_padded_name_trimmed(): void
    {
        $response = $this->postJson(route('invoices.store'), [
            ...self::withLine([...self::LINE, 'name' => '  Consulting  ']),
            'customer_name' => "\t Jane Doe \t",
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.customer_name', 'Jane Doe')
            ->assertJsonPath('data.product_lines.0.name', 'Consulting');
        $this->assertDatabaseHas('invoices', ['id' => $response->json('data.id'), 'customer_name' => 'Jane Doe']);
        $this->assertDatabaseHas('invoice_product_lines', ['name' => 'Consulting']);
        $this->assertMatchesContract($response, 201);
    }

    /**
     * A value over the limit after trimming already fails the rule; the check of
     * the input as sent adds no second message.
     */
    public function test_reports_a_name_over_the_limit_after_trimming_once(): void
    {
        $name = str_repeat('n', 256);

        $response = $this->postJson(route('invoices.store'), [
            ...self::withLine([...self::LINE, 'name' => $name]),
            'customer_name' => $name,
            'customer_email' => self::validEmailOfLength(256),
        ]);

        $response->assertUnprocessable();
        self::assertSame([
            'customer_name' => ['The customer name field must not be greater than 255 characters.'],
            'customer_email' => ['The customer email field must not be greater than 255 characters.'],
            'product_lines.0.name' => ['The product_lines.0.name field must not be greater than 255 characters.'],
        ], $response->json('errors'));
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * A name that fits once trimmed fails only the check of the input as sent,
     * which adds the failure under the studly rule name 'Max', so the client
     * gets the rule's own string message.
     */
    public function test_reports_the_max_message_for_a_name_over_the_limit_as_sent(): void
    {
        $response = $this->postJson(route('invoices.store'), [...self::PAYLOAD, 'customer_name' => "\t".str_repeat('n', 255)]);

        $response->assertUnprocessable();
        self::assertSame([
            'customer_name' => ['The customer name field must not be greater than 255 characters.'],
        ], $response->json('errors'));
        $this->assertMatchesContract($response, 422, validRequest: false);
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
        yield 'customer email of 256 characters' => [[...self::PAYLOAD, 'customer_email' => self::validEmailOfLength(256)], 'customer_email'];
        yield 'customer name of 255 characters after a tab' => [[...self::PAYLOAD, 'customer_name' => "\t".str_repeat('n', 255)], 'customer_name'];
        yield 'customer email of 255 characters after a tab' => [[...self::PAYLOAD, 'customer_email' => "\t".self::validEmailOfLength(255)], 'customer_email'];
        yield 'product lines as a string' => [[...self::PAYLOAD, 'product_lines' => 'x'], 'product_lines'];
        yield 'product lines as an object' => [[...self::PAYLOAD, 'product_lines' => ['first' => self::LINE]], 'product_lines'];
        yield 'product lines as an empty object' => [[...self::PAYLOAD, 'product_lines' => (object) []], 'product_lines'];
        yield 'product lines as an object with numeric keys' => [[...self::PAYLOAD, 'product_lines' => (object) [self::LINE]], 'product_lines'];
        yield 'product lines as an object next to a NUL-prefixed key' => [[...self::PAYLOAD, 'product_lines' => (object) [], "\0" => 0], 'product_lines'];
        yield '1001 product lines' => [[...self::PAYLOAD, 'product_lines' => array_fill(0, 1001, self::LINE)], 'product_lines'];
        yield 'empty line name' => [self::withLine([...self::LINE, 'name' => '']), 'product_lines.0.name'];
        yield 'blank line name' => [self::withLine([...self::LINE, 'name' => '   ']), 'product_lines.0.name'];
        yield 'line name of 256 characters' => [self::withLine([...self::LINE, 'name' => str_repeat('n', 256)]), 'product_lines.0.name'];
        yield 'line name of 255 characters after a tab' => [self::withLine([...self::LINE, 'name' => "\t".str_repeat('n', 255)]), 'product_lines.0.name'];
        yield 'second line name of 255 characters after a soft hyphen' => [[...self::PAYLOAD, 'product_lines' => [self::LINE, [...self::LINE, 'name' => "\u{00AD}".str_repeat('n', 255)]]], 'product_lines.1.name'];
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
     * @return Iterator<string, array{mixed}>
     */
    public static function productLinesOfABodyThatIsNotRead(): Iterator
    {
        yield 'an empty string' => [''];
        yield 'an object' => [(object) []];
    }

    /**
     * Laravel trims strings and turns empty ones into null only in a request
     * marked as JSON; read without that, `""` for product_lines slipped past
     * the array rules and "  Jane  " was stored untrimmed. Nor does the check
     * of product_lines' shape read such a body.
     */
    #[DataProvider('productLinesOfABodyThatIsNotRead')]
    public function test_does_not_read_a_body_without_a_json_content_type(mixed $productLines): void
    {
        $response = $this->call(
            'POST',
            route('invoices.store'),
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            content: (string) json_encode([...self::PAYLOAD, 'customer_name' => '  Jane  ', 'product_lines' => $productLines]),
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_name', 'customer_email']);
        $this->assertDatabaseCount('invoices', 0);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * The length check of the input as sent reads the raw body only of a request
     * marked as JSON: a line name of 256 characters in a body of another type
     * adds no error of its own, the missing fields are the only ones.
     */
    public function test_does_not_measure_the_text_of_a_body_without_a_json_content_type(): void
    {
        $response = $this->call(
            'POST',
            route('invoices.store'),
            server: ['CONTENT_TYPE' => 'text/plain', 'HTTP_ACCEPT' => 'application/json'],
            content: (string) json_encode(self::withLine([...self::LINE, 'name' => str_repeat('n', 256)])),
        );

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['customer_name', 'customer_email'])
            ->assertJsonMissingValidationErrors(['product_lines.0.name']);
        $this->assertMatchesContract($response, 422, validRequest: false);
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function jsonBodiesThatAreNotObjects(): Iterator
    {
        yield 'a string' => ['"Jane Doe"'];
        yield 'a number' => ['5'];
        yield 'null' => ['null'];
    }

    #[DataProvider('jsonBodiesThatAreNotObjects')]
    public function test_answers_422_to_a_json_body_that_is_not_an_object(string $body): void
    {
        $response = $this->call(
            'POST',
            route('invoices.store'),
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            content: $body,
        );

        $response->assertUnprocessable()->assertJsonValidationErrors(['customer_name', 'customer_email']);
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
     * length limit decides. Needs a length of at least 249: the local part is
     * `$length - 248` characters.
     */
    private static function validEmailOfLength(int $length): string
    {
        return str_repeat('a', $length - 248).'@'.implode('.', array_fill(0, 4, str_repeat('b', 60))).'.com';
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
