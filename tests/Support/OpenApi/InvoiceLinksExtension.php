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

    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        $linked = match ($routeInfo->route->getName()) {
            'invoices.store' => [
                HttpResponse::HTTP_CREATED,
                [
                    'ShowInvoice' => new Link(operationId: 'invoices.show', parameters: ['invoice' => self::INVOICE_ID_FROM_BODY]),
                    'SendInvoice' => new Link(operationId: 'invoices.send', parameters: ['invoice' => self::INVOICE_ID_FROM_BODY]),
                ],
            ],
            'invoices.send' => [
                HttpResponse::HTTP_OK,
                [
                    'ShowInvoice' => new Link(operationId: 'invoices.show', parameters: ['invoice' => self::INVOICE_ID_FROM_BODY]),
                    'ReportDelivery' => new Link(
                        operationId: 'notification.hook',
                        parameters: ['action' => 'delivered', 'reference' => self::INVOICE_ID_FROM_BODY],
                    ),
                ],
            ],
            default => null,
        };

        if ($linked === null) {
            return;
        }

        [$code, $links] = $linked;

        foreach ($operation->responses ?? [] as $response) {
            if (! $response instanceof Response || $response->code !== $code) {
                continue;
            }

            foreach ($links as $name => $link) {
                $response->addLink($name, $link);
            }
        }
    }
}
