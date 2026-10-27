<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Application\Exceptions\NotificationFailed;
use Modules\Invoices\Application\Exceptions\SendNotRecorded;
use Modules\Invoices\Application\Services\CreateInvoiceService;
use Modules\Invoices\Application\Services\FindInvoiceService;
use Modules\Invoices\Application\Services\SendInvoiceService;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceNotSendable;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Presentation\Http\Requests\CreateInvoiceRequest;
use Modules\Invoices\Presentation\Http\Resources\InvoiceResource;

/**
 * Domain exceptions are mapped to HTTP by InvoiceServiceProvider, and each
 * action's @throws tags name the mapped ones it answers with, for the API
 * document. Action PHPDoc summaries are published there, so the reasons live
 * here. store() answers an overflowing total as a validation error, since it
 * is a fault in the submitted lines, and names no id because the invoice was
 * rolled back.
 */
final readonly class InvoiceController
{
    public function __construct(
        private CreateInvoiceService $createInvoice,
        private FindInvoiceService $findInvoice,
        private SendInvoiceService $sendInvoice,
    ) {}

    public function store(CreateInvoiceRequest $request): JsonResponse
    {
        try {
            $invoice = $this->createInvoice->handle($request->toData());
        } catch (InvoiceTotalOverflow) {
            throw ValidationException::withMessages([
                'product_lines' => 'The total of the product lines must not exceed '.PHP_INT_MAX.'.',
            ]);
        }

        return new InvoiceResource($invoice)
            ->response()
            ->setStatusCode(JsonResponse::HTTP_CREATED);
    }

    /**
     * @param  string  $invoice  The invoice UUID.
     *
     * @throws InvoiceNotFound
     */
    public function show(string $invoice): InvoiceResource
    {
        return new InvoiceResource($this->findInvoice->handle($invoice));
    }

    /**
     * @param  string  $invoice  The invoice UUID.
     *
     * @throws InvoiceNotFound
     * @throws InvalidInvoiceStatusTransition
     * @throws InvoiceNotSendable
     * @throws NotificationFailed
     * @throws SendNotRecorded
     */
    public function send(string $invoice): InvoiceResource
    {
        return new InvoiceResource($this->sendInvoice->handle($invoice));
    }
}
