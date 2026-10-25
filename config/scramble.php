<?php

declare(strict_types=1);

use Tests\Support\OpenApi\InvoiceExceptionsToResponseExtension;
use Tests\Support\OpenApi\InvoiceTotalPriceReturnTypeExtension;

/**
 * Only what the project changes; Scramble merges its own defaults under this
 * file. Scramble and the extensions are dev-only: without them (`--no-dev`)
 * nothing reads this file.
 */
return [
    'extensions' => [
        InvoiceExceptionsToResponseExtension::class,
        InvoiceTotalPriceReturnTypeExtension::class,
    ],
];
