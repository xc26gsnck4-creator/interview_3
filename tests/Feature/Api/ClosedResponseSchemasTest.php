<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Closure;
use ErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Modules\Invoices\Domain\Models\Invoice;
use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\ValidatesOpenApiContract;
use Tests\TestCase;
use Throwable;

/**
 * Spectator has no strict mode and Scramble never writes
 * `additionalProperties: false`, so without the closing done by
 * Tests\Support\OpenApi\ClosedResponseObjectsExtension a response field that
 * api.json does not describe would pass every contract check.
 */
final class ClosedResponseSchemasTest extends TestCase
{
    use ValidatesOpenApiContract;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommittedOpenApiDocument();
    }

    /**
     * The middleware is appended to the `api` group, so it runs inside
     * Spectator's (prepended) and the contract check sees the extra key.
     */
    public function test_contract_check_rejects_an_undocumented_field_in_a_response(): void
    {
        $this->app->instance('add-extra-product-line-field', new class
        {
            /**
             * @param  Closure(Request): Response  $next
             */
            public function handle(Request $request, Closure $next): Response
            {
                $response = $next($request);

                if ($response instanceof JsonResponse) {
                    $body = $response->getData(true);

                    if (is_array($body) && is_array($body['data'] ?? null) && is_array($body['data']['product_lines'] ?? null)) {
                        $lines = $body['data']['product_lines'];
                        $lines[0] = [...(array) $lines[0], 'extra' => 'undocumented'];
                        $body['data']['product_lines'] = $lines;
                        $response->setData($body);
                    }
                }

                return $response;
            }
        });
        $this->app->make(Router::class)->pushMiddlewareToGroup('api', 'add-extra-product-line-field');
        $invoice = Invoice::factory()->withLines()->create();

        $response = $this->getJson(route('invoices.show', $invoice->id));

        $response->assertOk()->assertJsonPath('data.product_lines.0.extra', 'undocumented');
        $this->assertThrows(
            fn () => $this->assertMatchesContract($response, 200),
            static fn (Throwable $exception): bool => $exception instanceof ErrorException && $exception->getPrevious() instanceof ExpectationFailedException,
            'Additional object properties are not allowed: extra',
        );
    }

    public function test_every_response_object_in_the_document_is_closed(): void
    {
        /** @var array<string, mixed> $document */
        $document = json_decode((string) file_get_contents(base_path('api.json')), true, flags: JSON_THROW_ON_ERROR);
        $openObjects = [];
        $visited = [];

        foreach ($this->responseRoots($document) as $path => $schema) {
            if (str_starts_with($path, '#/')) {
                if (isset($visited[$path])) {
                    continue;
                }

                $visited[$path] = true;
            }

            $this->walkSchema($document, $schema, $path, $openObjects, $visited);
        }

        self::assertSame([], $openObjects, 'These response objects have properties but no additionalProperties.');
        self::assertArrayHasKey('#/components/schemas/InvoiceProductLineResource', $visited);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function responseRoots(array $document): array
    {
        $roots = [];

        foreach ($this->asArray($document['paths'] ?? null) as $uri => $operations) {
            foreach ($this->asArray($operations) as $method => $operation) {
                foreach ($this->asArray($this->asArray($operation)['responses'] ?? null) as $status => $response) {
                    $roots[sprintf('%s %s %s', $method, $uri, $status)] = $response;
                }
            }
        }

        foreach ($this->asArray($this->asArray($document['components'] ?? null)['responses'] ?? null) as $name => $response) {
            $roots['#/components/responses/'.$name] = $response;
        }

        return $roots;
    }

    /**
     * Follows `$ref`, `content.*.schema`, `properties` and `items`, each
     * reference once.
     *
     * @param  array<string, mixed>  $document
     * @param  list<string>|array<string, string>  $openObjects
     * @param  array<string, true>  $visited
     */
    private function walkSchema(array $document, mixed $node, string $path, array &$openObjects, array &$visited): void
    {
        $node = $this->asArray($node);

        if (isset($node['$ref']) && is_string($node['$ref'])) {
            $reference = $node['$ref'];

            if (isset($visited[$reference])) {
                return;
            }

            $visited[$reference] = true;
            $target = $document;

            foreach (explode('/', substr($reference, 2)) as $segment) {
                $target = $this->asArray($target)[$segment] ?? null;
            }

            $this->walkSchema($document, $target, $reference, $openObjects, $visited);

            return;
        }

        if (isset($node['properties']) && ! array_key_exists('additionalProperties', $node)) {
            $openObjects[] = $path;
        }

        foreach ($this->asArray($node['content'] ?? null) as $mediaType => $media) {
            $this->walkSchema($document, $this->asArray($media)['schema'] ?? null, $path.' '.$mediaType, $openObjects, $visited);
        }

        foreach ($this->asArray($node['properties'] ?? null) as $name => $property) {
            $this->walkSchema($document, $property, $path.'.'.$name, $openObjects, $visited);
        }

        if (isset($node['items'])) {
            $this->walkSchema($document, $node['items'], $path.'[]', $openObjects, $visited);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
