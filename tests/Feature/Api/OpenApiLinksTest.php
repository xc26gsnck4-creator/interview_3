<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Schemathesis follows the links in api.json (added by
 * Tests\Support\OpenApi\InvoiceLinksExtension) to reach an existing invoice; a
 * link to an operation or a parameter the document does not have would not be
 * followed, and send and the webhook would only be fuzzed into a 404.
 */
final class OpenApiLinksTest extends TestCase
{
    private const array EXPECTED_LINKS = [
        'post /invoices 201 ShowInvoice' => 'invoices.show',
        'post /invoices 201 SendInvoice' => 'invoices.send',
        'post /invoices/{invoice}/send 200 ShowInvoice' => 'invoices.show',
        'post /invoices/{invoice}/send 200 ReportDelivery' => 'notification.hook',
    ];

    public function test_every_link_names_an_operation_and_its_parameters(): void
    {
        $parametersByOperation = [];
        $links = [];

        foreach (Arr::array(File::json(base_path('api.json'), JSON_THROW_ON_ERROR), 'paths') as $uri => $pathItem) {
            foreach ($this->asArray($pathItem) as $method => $operation) {
                $operation = $this->asArray($operation);
                $parametersByOperation[Arr::string($operation, 'operationId')] = array_column(Arr::array($operation, 'parameters', []), 'name');

                foreach (Arr::array($operation, 'responses') as $status => $response) {
                    foreach (Arr::array($this->asArray($response), 'links', []) as $name => $link) {
                        $links[sprintf('%s %s %s %s', $method, $uri, $status, $name)] = $this->asArray($link);
                    }
                }
            }
        }

        foreach (self::EXPECTED_LINKS as $source => $operationId) {
            self::assertSame($operationId, $links[$source]['operationId'] ?? null, "api.json lacks the link {$source}.");
        }

        foreach ($links as $source => $link) {
            $operationId = Arr::string($link, 'operationId');
            self::assertArrayHasKey($operationId, $parametersByOperation, "{$source} links to {$operationId}, which api.json does not have.");

            foreach (array_keys(Arr::array($link, 'parameters', [])) as $parameter) {
                self::assertContains($parameter, $parametersByOperation[$operationId], "{$source} sets {$parameter}, which {$operationId} does not take.");
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
