<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Link;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Http\Response as HttpResponse;

/**
 * Links the invoice operations in the API document: the create 201 leads to
 * show and send, the send 200 to show and the delivery webhook. Schemathesis
 * builds stateful scenarios from OpenAPI links; without them it never reaches
 * an existing invoice, so send and the webhook only answer 404. Scramble is a
 * dev dependency, so this class lives in autoload-dev and config/scramble.php
 * registers it.
 */
final class InvoiceLinksExtension extends OperationExtension
{
    private const string INVOICE_ID_FROM_BODY = '$response.body#/data/id';

    /**
     * Route name, then response code, then link name: the target operation id
     * and its parameters.
     *
     * @var array<string, array<int, array<string, array{string, array<string, string>}>>>
     */
    public const array LINKS = [
        'invoices.store' => [
            HttpResponse::HTTP_CREATED => [
                'ShowInvoice' => ['invoices.show', ['invoice' => self::INVOICE_ID_FROM_BODY]],
                'SendInvoice' => ['invoices.send', ['invoice' => self::INVOICE_ID_FROM_BODY]],
            ],
        ],
        'invoices.send' => [
            HttpResponse::HTTP_OK => [
                'ShowInvoice' => ['invoices.show', ['invoice' => self::INVOICE_ID_FROM_BODY]],
                'ReportDelivery' => ['notification.hook', ['action' => 'delivered', 'reference' => self::INVOICE_ID_FROM_BODY]],
            ],
        ],
    ];

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $linksByCode = self::LINKS[$routeInfo->route->getName() ?? ''] ?? [];

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || ! is_int($response->code)) {
                continue;
            }

            foreach ($linksByCode[$response->code] ?? [] as $name => [$operationId, $parameters]) {
                $response->addLink($name, new Link(operationId: $operationId, parameters: $parameters));
            }
        }
    }
}
