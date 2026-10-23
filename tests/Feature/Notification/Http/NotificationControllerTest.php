<?php

declare(strict_types=1);

namespace Tests\Feature\Notification\Http;

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use Tests\Concerns\ValidatesOpenApiContract;
use Tests\TestCase;

final class NotificationControllerTest extends TestCase
{
    use ValidatesOpenApiContract;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFaker();
        $this->useCommittedOpenApiDocument();
    }

    public function test_delivered_hook_dispatches_event(): void
    {
        Event::fake([WebhookDeliveredEvent::class]);

        $uri = route('notification.hook', [
            'action' => 'delivered',
            'reference' => $this->faker->uuid(),
        ]);

        $this->assertMatchesContract($this->getJson($uri)->assertNoContent(), 204);

        Event::assertDispatched(WebhookDeliveredEvent::class);
    }

    public function test_unknown_action_returns_not_found(): void
    {
        $uri = route('notification.hook', [
            'action' => 'unknownaction',
            'reference' => $this->faker->uuid(),
        ]);

        $this->assertMatchesContract($this->getJson($uri)->assertNotFound(), 404, validRequest: false);
    }

    public function test_invalid_reference_pattern_returns_not_found(): void
    {
        $uri = route('notification.hook', [
            'action' => 'delivered',
            'reference' => 'not-a-uuid',
        ]);

        $this->getJson($uri)->assertNotFound();
    }
}
