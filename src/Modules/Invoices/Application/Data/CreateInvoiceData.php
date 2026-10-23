<?php

declare(strict_types=1);

namespace Modules\Invoices\Application\Data;

final readonly class CreateInvoiceData
{
    /**
     * @param  list<ProductLineData>  $productLines
     */
    public function __construct(
        public string $customerName,
        public string $customerEmail,
        public array $productLines = [],
    ) {}
}
