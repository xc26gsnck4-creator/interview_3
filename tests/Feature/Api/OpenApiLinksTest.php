<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\ResolvesJsonPointers;
use Tests\Support\OpenApi\InvoiceLinksExtension;
use Tests\TestCase;

/**
 * Schemathesis follows the links in api.json (added by
 * Tests\Support\OpenApi\InvoiceLinksExtension) to reach an existing invoice; a
 * link to an operation or a parameter the document does not have, one that
 * leaves a required path parameter unset, or one that reads a property the
 * response body does not have, would not be followed, and send and the webhook
 * would only be fuzzed into a 404. Every link the extension declares must be in
 * the committed document.
 */
final class OpenApiLinksTest extends TestCase
{
    use ResolvesJsonPointers;

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
        $linksByOperation = [];

        foreach (Arr::array($this->document, 'paths') as $uri => $pathItem) {
            foreach (Arr::only($this->asArray($pathItem), self::HTTP_METHODS) as $method => $operation) {
                $operation = $this->asArray($operation);
                $operationId = Arr::string($operation, 'operationId');
                $parametersByOperation[$operationId] = Arr::array($operation, 'parameters', []);

                foreach (Arr::array($operation, 'responses') as $status => $response) {
                    $response = $this->asArray($response);

                    foreach (Arr::array($response, 'links', []) as $name => $link) {
                        $link = $this->asArray($link);
                        $linksByOperation[$operationId][$status][$name] = $link;
                        $links[sprintf('%s %s %s %s', $method, $uri, $status, $name)] = [$link, Arr::get($response, 'content.application/json.schema')];
                    }
                }
            }
        }

        foreach (InvoiceLinksExtension::LINKS as $routeName => $linksByCode) {
            foreach ($linksByCode as $code => $linksByName) {
                foreach ($linksByName as $name => [$targetOperationId, $parameters]) {
                    $documented = $linksByOperation[$routeName][$code][$name] ?? [];
                    self::assertSame(
                        $targetOperationId,
                        $documented['operationId'] ?? null,
                        "api.json lacks the link {$name} of {$routeName} {$code} to {$targetOperationId}.",
                    );
                    self::assertSame(
                        $parameters,
                        $documented['parameters'] ?? null,
                        "api.json declares other parameters for the link {$name} of {$routeName} {$code} than the extension.",
                    );
                }
            }
        }

        foreach ($links as $source => [$link, $bodySchema]) {
            $operationId = Arr::string($link, 'operationId');
            self::assertArrayHasKey($operationId, $parametersByOperation, "{$source} links to {$operationId}, which api.json does not have.");
            $targetParameters = $parametersByOperation[$operationId];
            $set = Arr::array($link, 'parameters', []);

            foreach ($targetParameters as $targetParameter) {
                $targetParameter = $this->asArray($targetParameter);

                if (($targetParameter['in'] ?? null) === 'path' && ($targetParameter['required'] ?? false) === true) {
                    $name = Arr::string($targetParameter, 'name');
                    self::assertArrayHasKey($name, $set, "{$source} does not set the path parameter {$name} of {$operationId}.");
                }
            }

            foreach ($set as $parameter => $value) {
                self::assertContains($parameter, array_column($targetParameters, 'name'), "{$source} sets {$parameter}, which {$operationId} does not take.");

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
        foreach ($this->pointerTokens($pointer) as $token) {
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
     * schema is inline; a reference that does not resolve, or one already
     * followed (a cycle), fails the test.
     *
     * @param  list<string>  $followed
     * @return array<array-key, mixed>
     */
    private function dereference(mixed $schema, array $followed = []): array
    {
        $schema = $this->asArray($schema);
        $reference = $schema['$ref'] ?? null;

        if (! is_string($reference)) {
            return $schema;
        }

        $target = in_array($reference, $followed, true) ? null : $this->resolvePointer($this->document, $reference);

        if ($target === null) {
            self::fail("unresolved \$ref {$reference}");
        }

        return $this->dereference($target, [...$followed, $reference]);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
