<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Closure;
use ErrorException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    /** @var array<array-key, mixed> */
    private array $document = [];

    /** @var list<string> */
    private array $openObjects = [];

    /** @var list<string> */
    private array $unresolved = [];

    /** @var list<string> */
    private array $allOfs = [];

    /** @var array<string, true> */
    private array $visited = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommittedOpenApiDocument();
    }

    /**
     * The middleware is appended to the `api` group through the HTTP kernel, so it runs inside
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
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('api', 'add-extra-product-line-field');

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
        $this->document = File::json(base_path('api.json'), JSON_THROW_ON_ERROR);

        foreach ($this->responseRoots() as $path => $response) {
            $this->walkSchema($response, $path);
        }

        self::assertSame([], $this->unresolved, 'These $ref values do not resolve inside api.json.');
        self::assertSame([], $this->allOfs, 'allOf in a response: do not close its branches one by one, close the parent with unevaluatedProperties: false.');
        self::assertSame([], $this->openObjects, 'These response objects have properties but additionalProperties is not false.');
        self::assertArrayHasKey('#/components/schemas/InvoiceProductLineResource', $this->visited);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseRoots(): array
    {
        $roots = [];

        foreach ($this->asArray(Arr::get($this->document, 'paths')) as $uri => $operations) {
            foreach ($this->asArray($operations) as $method => $operation) {
                foreach ($this->asArray(Arr::get($this->asArray($operation), 'responses')) as $status => $response) {
                    $roots[sprintf('%s %s %s', $method, $uri, $status)] = $response;
                }
            }
        }

        foreach (array_keys($this->asArray(Arr::get($this->document, 'components.responses'))) as $name) {
            $roots['#/components/responses/'.$name] = ['$ref' => '#/components/responses/'.$name];
        }

        return $roots;
    }

    /**
     * Follows `$ref` (each once), `content.*.schema`, `properties`, `items`,
     * `anyOf`, `oneOf` and a schema-valued `additionalProperties`. An object
     * with properties is open unless additionalProperties is false. An `allOf`
     * is not descended into: closing its branches one by one would make them
     * reject each other's properties, so it is recorded unless the node itself
     * has `unevaluatedProperties: false`.
     */
    private function walkSchema(mixed $node, string $path): void
    {
        $node = $this->asArray($node);
        $reference = $node['$ref'] ?? null;

        if (is_string($reference)) {
            if (! isset($this->visited[$reference])) {
                $this->visited[$reference] = true;
                $target = $this->resolvePointer($reference);

                if ($target === null) {
                    $this->unresolved[] = $path.' -> '.$reference;
                } else {
                    $this->walkSchema($target, $reference);
                }
            }

            return;
        }

        if (isset($node['properties']) && ($node['additionalProperties'] ?? null) !== false) {
            $this->openObjects[] = $path;
        }

        foreach ($this->asArray($node['content'] ?? null) as $mediaType => $media) {
            $this->walkSchema($this->asArray($media)['schema'] ?? null, $path.' '.$mediaType);
        }

        foreach ($this->asArray($node['properties'] ?? null) as $name => $property) {
            $this->walkSchema($property, $path.'.'.$name);
        }

        if (isset($node['allOf']) && ($node['unevaluatedProperties'] ?? null) !== false) {
            $this->allOfs[] = $path;
        }

        foreach (['anyOf', 'oneOf'] as $keyword) {
            foreach ($this->asArray($node[$keyword] ?? null) as $index => $branch) {
                $this->walkSchema($branch, sprintf('%s.%s[%s]', $path, $keyword, $index));
            }
        }

        foreach (['items' => '[]', 'additionalProperties' => '.*'] as $keyword => $suffix) {
            if (isset($node[$keyword])) {
                $this->walkSchema($node[$keyword], $path.$suffix);
            }
        }
    }

    /**
     * Resolves a local `#/a/b` reference segment by segment (RFC 6901: `~1` is
     * `/`, `~0` is `~`), so a component name with dots resolves too.
     *
     * @return array<array-key, mixed>|null
     */
    private function resolvePointer(string $reference): ?array
    {
        if (! str_starts_with($reference, '#/')) {
            return null;
        }

        $target = $this->document;

        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);

            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                return null;
            }

            $target = $target[$segment];
        }

        return is_array($target) ? $target : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function asArray(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
