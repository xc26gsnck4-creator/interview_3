<?php

declare(strict_types=1);

namespace Modules\Notifications\Application\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use Ramsey\Uuid\UuidInterface;

final readonly class NotificationService
{
    public function __construct(
        private Dispatcher $dispatcher,
    ) {}

    public function delivered(UuidInterface $reference): void
    {
        $this->dispatcher->dispatch(new WebhookDeliveredEvent(
            resourceId: $reference,
        ));
    }
}
