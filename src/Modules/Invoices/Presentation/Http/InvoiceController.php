<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Modules\Invoices\Application\Services\CreateInvoiceService;
use Modules\Invoices\Application\Services\FindInvoiceService;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Presentation\Http\Requests\CreateInvoiceRequest;
use Modules\Invoices\Presentation\Http\Resources\InvoiceResource;

/**
 * Each action calls one application service. Domain exceptions are mapped to
 * HTTP by InvoiceServiceProvider; the @throws tags name the ones each action
 * answers with, for the API document.
 */
final readonly class InvoiceController
{
    public function __construct(
        private CreateInvoiceService $createInvoice,
        private FindInvoiceService $findInvoice,
    ) {}

    /**
     * @throws InvoiceTotalOverflow
     */
    public function store(CreateInvoiceRequest $request): JsonResponse
    {
        return new InvoiceResource($this->createInvoice->handle($request->toData()))
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
}
