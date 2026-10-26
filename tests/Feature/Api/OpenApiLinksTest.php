<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Schemathesis follows the links in api.json (added by
 * Tests\Support\OpenApi\InvoiceLinksExtension) to reach an existing invoice; a
 * link to an operation or a parameter the document does not have, or one that
 * reads a property the response body does not have, would not be followed, and
 * send and the webhook would only be fuzzed into a 404.
 */
final class OpenApiLinksTest extends TestCase
{
    private const array EXPECTED_LINKS = [
        'post /invoices 201 ShowInvoice' => 'invoices.show',
        'post /invoices 201 SendInvoice' => 'invoices.send',
        'post /invoices/{invoice}/send 200 ShowInvoice' => 'invoices.show',
        'post /invoices/{invoice}/send 200 ReportDelivery' => 'notification.hook',
    ];

    /**
     * The fields of a path item that hold an operation; the others
     * (`parameters`, `summary`, `servers`, ...) are not operations.
     */
    private const array HTTP_METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    /**
     * The runtime expression of a link value read from the response body; a
     * JSON pointer follows it.
     */
    private const string RESPONSE_BODY = '$response.body#';

    /** @var array<array-key, mixed> */
    private array $document = [];

    public function test_every_link_names_an_operation_its_parameters_and_a_response_property(): void
    {
        $this->document = File::json(base_path('api.json'), JSON_THROW_ON_ERROR);
        $parametersByOperation = [];
        $links = [];

        foreach (Arr::array($this->document, 'paths') as $uri => $pathItem) {
            foreach (Arr::only($this->asArray($pathItem), self::HTTP_METHODS) as $method => $operation) {
                $operation = $this->asArray($operation);
                $parametersByOperation[Arr::string($operation, 'operationId')] = array_column(Arr::array($operation, 'parameters', []), 'name');

                foreach (Arr::array($operation, 'responses') as $status => $response) {
                    $response = $this->asArray($response);

                    foreach (Arr::array($response, 'links', []) as $name => $link) {
                        $links[sprintf('%s %s %s %s', $method, $uri, $status, $name)] = [$this->asArray($link), Arr::get($response, 'content.application/json.schema')];
                    }
                }
            }
        }

        foreach (self::EXPECTED_LINKS as $source => $operationId) {
            self::assertSame($operationId, $links[$source][0]['operationId'] ?? null, "api.json lacks the link {$source}.");
        }

        foreach ($links as $source => [$link, $bodySchema]) {
            $operationId = Arr::string($link, 'operationId');
            self::assertArrayHasKey($operationId, $parametersByOperation, "{$source} links to {$operationId}, which api.json does not have.");

            foreach (Arr::array($link, 'parameters', []) as $parameter => $value) {
                self::assertContains($parameter, $parametersByOperation[$operationId], "{$source} sets {$parameter}, which {$operationId} does not take.");

                if (is_string($value) && str_starts_with($value, self::RESPONSE_BODY)) {
                    self::assertTrue(
                        $this->hasProperty($bodySchema, Str::after($value, self::RESPONSE_BODY)),
                        "{$source} sets {$parameter} from {$value}, which its response body does not have.",
                    );
                }
            }
        }
    }

    /**
     * Walks a JSON pointer through the `properties` of a schema, following
     * `$ref` at every step; true when every token names a property.
     */
    private function hasProperty(mixed $schema, string $pointer): bool
    {
        foreach ($this->tokens($pointer) as $token) {
            $properties = $this->asArray($this->dereference($schema)['properties'] ?? null);

            if (! array_key_exists($token, $properties)) {
                return false;
            }

            $schema = $properties[$token];
        }

        return true;
    }

    /**
     * Follows a local `$ref` (`#/components/...`) into api.json until the
     * schema is inline.
     *
     * @return array<array-key, mixed>
     */
    private function dereference(mixed $schema): array
    {
        $schema = $this->asArray($schema);
        $reference = $schema['$ref'] ?? null;

        if (! is_string($reference)) {
            return $schema;
        }

        $target = $this->document;

        foreach ($this->tokens(Str::after($reference, '#')) as $token) {
            $target = $this->asArray($target)[$token] ?? null;
        }

        return $this->dereference($target);
    }

    /**
     * The tokens of a JSON pointer (RFC 6901: `~1` is `/`, `~0` is `~`).
     *
     * @return list<string>
     */
    private function tokens(string $pointer): array
    {
        return array_map(
            static fn (string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            array_slice(explode('/', $pointer), 1),
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
