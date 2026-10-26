<?php

declare(strict_types=1);

use Tests\Support\OpenApi\ClosedResponseObjectsExtension;
use Tests\Support\OpenApi\DatabaseLockedResponseExtension;
use Tests\Support\OpenApi\InvoiceExceptionsToResponseExtension;
use Tests\Support\OpenApi\InvoiceLinksExtension;
use Tests\Support\OpenApi\InvoiceTotalPriceReturnTypeExtension;

/**
 * Only what the project changes; Scramble merges its own defaults under this
 * file. Scramble and the extensions are dev-only: without them (`--no-dev`)
 * nothing reads this file. InvoiceLinksExtension links the create and send
 * responses to the operations that follow them, so a fuzzer can reach an
 * existing invoice. ClosedResponseObjectsExtension is last so it also
 * closes the responses the other extensions add.
 */
return [
    'extensions' => [
        DatabaseLockedResponseExtension::class,
        InvoiceExceptionsToResponseExtension::class,
        InvoiceTotalPriceReturnTypeExtension::class,
        InvoiceLinksExtension::class,
        ClosedResponseObjectsExtension::class,
    ],
];
