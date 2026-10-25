<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Invoices\Application\Services\CreateInvoiceService;
use Modules\Invoices\Application\Services\FindInvoiceService;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Presentation\Http\Requests\CreateInvoiceRequest;
use Modules\Invoices\Presentation\Http\Resources\InvoiceResource;

/**
 * Each action calls one application service. Domain exceptions are mapped to
 * HTTP by InvoiceServiceProvider; an action's @throws tags name the mapped
 * ones it answers with, for the API document (store's 422s come from its form
 * request). Action PHPDoc summaries are published in the API document, so the
 * reasons live here.
 *
 * store(): lines whose total does not fit in int are a fault in the submitted
 * lines, so they answer the same validation error as any other bad line; the
 * invoice was rolled back, so the message names no id. Anywhere else an
 * overflowing total is corrupt stored data and stays a server error.
 */
final readonly class InvoiceController
{
    public function __construct(
        private CreateInvoiceService $createInvoice,
        private FindInvoiceService $findInvoice,
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
}
