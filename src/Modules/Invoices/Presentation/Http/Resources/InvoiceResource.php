<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoices\Domain\Models\Invoice;
use Override;

/**
 * The services load the lines; the total is computed from the stored lines on
 * every render.
 *
 * @property-read Invoice $resource
 */
final class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status,
            'customer_name' => $this->resource->customer_name,
            'customer_email' => $this->resource->customer_email,
            'product_lines' => InvoiceProductLineResource::collection($this->resource->productLines),
            'total_price' => $this->resource->totalPrice(),
        ];
    }
}
