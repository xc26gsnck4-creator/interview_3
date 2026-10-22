<?php

declare(strict_types=1);

namespace Tests\Feature\Notification\Http;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use Tests\TestCase;

final class NotificationControllerTest extends TestCase
{
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFaker();
    }

    public function testDeliveredHookDispatchesEvent(): void
    {
        Event::fake([WebhookDeliveredEvent::class]);

        $uri = route('notification.hook', [
            'action' => 'delivered',
            'reference' => $this->faker->uuid(),
        ]);

        $this->getJson($uri)->assertNoContent();

        Event::assertDispatched(WebhookDeliveredEvent::class);
    }

    public function testUnknownActionReturnsNotFound(): void
    {
        $uri = route('notification.hook', [
            'action' => 'unknownaction',
            'reference' => $this->faker->uuid(),
        ]);

        $this->getJson($uri)->assertNotFound();
    }

    public function testInvalidReferencePatternReturnsNotFound(): void
    {
        $uri = route('notification.hook', [
            'action' => 'delivered',
            'reference' => 'not-a-uuid',
        ]);

        $this->getJson($uri)->assertNotFound();
    }
}
