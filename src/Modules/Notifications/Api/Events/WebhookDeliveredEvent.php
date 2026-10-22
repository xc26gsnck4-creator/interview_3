<?php

declare(strict_types=1);

namespace Modules\Notifications\Api\Events;

use Ramsey\Uuid\UuidInterface;

final readonly class WebhookDeliveredEvent
{
    public function __construct(
        public UuidInterface $resourceId,
    ) {}
}
