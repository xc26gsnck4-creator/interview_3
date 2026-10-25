<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Closure;
use ErrorException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Modules\Invoices\Domain\Models\Invoice;
use PHPUnit\Framework\ExpectationFailedException;
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
             * @param  Closure(Request): JsonResponse  $next
             */
            public function handle(Request $request, Closure $next): JsonResponse
            {
                $response = $next($request);
                $body = $response->getData(true);
                data_set($body, 'data.product_lines.0.extra', 'undocumented');

                return $response->setData($body);
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
        $document = File::json(base_path('api.json'), JSON_THROW_ON_ERROR);
        $openObjects = [];
        $visited = [];

        foreach ($this->responseRoots($document) as $path => $response) {
            $this->walkSchema($document, $response, $path, $openObjects, $visited);
        }

        self::assertSame([], $openObjects, 'These response objects have properties but additionalProperties is not false.');
        self::assertArrayHasKey('#/components/schemas/InvoiceProductLineResource', $visited);
    }

    /**
     * @param  array<array-key, mixed>  $document
     * @return array<string, mixed>
     */
    private function responseRoots(array $document): array
    {
        $roots = [];

        foreach ($this->asArray(Arr::get($document, 'paths')) as $uri => $operations) {
            foreach ($this->asArray($operations) as $method => $operation) {
                foreach ($this->asArray(Arr::get($this->asArray($operation), 'responses')) as $status => $response) {
                    $roots[sprintf('%s %s %s', $method, $uri, $status)] = $response;
                }
            }
        }

        foreach (array_keys($this->asArray(Arr::get($document, 'components.responses'))) as $name) {
            $roots['#/components/responses/'.$name] = ['$ref' => '#/components/responses/'.$name];
        }

        return $roots;
    }

    /**
     * Follows `$ref` (each once), `content.*.schema`, `properties`, `items`,
     * `allOf`, `anyOf`, `oneOf` and a schema-valued `additionalProperties`. An
     * object with properties is open unless additionalProperties is false.
     *
     * @param  array<array-key, mixed>  $document
     * @param  list<string>  $openObjects
     * @param  array<string, true>  $visited
     */
    private function walkSchema(array $document, mixed $node, string $path, array &$openObjects, array &$visited): void
    {
        $node = $this->asArray($node);
        $reference = $node['$ref'] ?? null;

        if (is_string($reference)) {
            if (! isset($visited[$reference])) {
                $visited[$reference] = true;
                $this->walkSchema($document, Arr::get($document, str_replace('/', '.', substr($reference, 2))), $reference, $openObjects, $visited);
            }

            return;
        }

        if (isset($node['properties']) && ($node['additionalProperties'] ?? null) !== false) {
            $openObjects[] = $path;
        }

        foreach ($this->asArray($node['content'] ?? null) as $mediaType => $media) {
            $this->walkSchema($document, $this->asArray($media)['schema'] ?? null, $path.' '.$mediaType, $openObjects, $visited);
        }

        foreach ($this->asArray($node['properties'] ?? null) as $name => $property) {
            $this->walkSchema($document, $property, $path.'.'.$name, $openObjects, $visited);
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $keyword) {
            foreach ($this->asArray($node[$keyword] ?? null) as $index => $branch) {
                $this->walkSchema($document, $branch, sprintf('%s.%s[%s]', $path, $keyword, $index), $openObjects, $visited);
            }
        }

        foreach (['items' => '[]', 'additionalProperties' => '.*'] as $keyword => $suffix) {
            if (isset($node[$keyword])) {
                $this->walkSchema($document, $node[$keyword], $path.$suffix, $openObjects, $visited);
            }
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
