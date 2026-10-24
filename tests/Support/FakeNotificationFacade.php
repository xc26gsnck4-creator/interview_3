<?php

declare(strict_types=1);

namespace Tests\Support;

use Modules\Notifications\Api\Data\NotifyData;
use Modules\Notifications\Api\NotificationFacadeInterface;
use Throwable;

final class FakeNotificationFacade implements NotificationFacadeInterface
{
    /** @var list<NotifyData> */
    public array $notified = [];

    public ?Throwable $failWith = null;

    public function notify(NotifyData $data): void
    {
        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }

        $this->notified[] = $data;
    }
}
