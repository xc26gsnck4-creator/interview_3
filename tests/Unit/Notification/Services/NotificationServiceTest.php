<?php

declare(strict_types=1);

namespace Tests\Unit\Notification\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Testing\WithFaker;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use Modules\Notifications\Application\Services\NotificationService;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class NotificationServiceTest extends TestCase
{
    use WithFaker;

    private Dispatcher $dispatcher;

    private NotificationService $notificationService;

    protected function setUp(): void
    {
        $this->setUpFaker();

        $this->dispatcher = $this->createMock(Dispatcher::class);
        $this->notificationService = new NotificationService($this->dispatcher);
    }

    public function test_delivered(): void
    {
        $this->dispatcher->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(WebhookDeliveredEvent::class));

        $this->notificationService->delivered(Uuid::fromString($this->faker->uuid()));
    }
}
