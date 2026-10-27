<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Override;

/**
 * @property-read InvoiceProductLine $resource
 */
final class InvoiceProductLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'quantity' => $this->resource->quantity->value,
            'unit_price' => $this->resource->unit_price->value,
            'total_unit_price' => $this->resource->totalUnitPrice(),
        ];
    }
}
