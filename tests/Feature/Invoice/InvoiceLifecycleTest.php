<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use Illuminate\Support\Facades\Log;
use Illuminate\Testing\Fluent\AssertableJson;
use Mockery\MockInterface;
use Mockery\VerificationDirector;
use Modules\Notifications\Api\NotificationFacadeInterface;
use RuntimeException;
use Tests\Concerns\ValidatesOpenApiContract;
use Tests\Support\FakeNotificationFacade;
use Tests\TestCase;

/**
 * The create, send, show and delivery-webhook endpoints used together through
 * the real routes, listener and event dispatch; only the notification facade is
 * a fake. The delivery reference comes from the notification the fake recorded,
 * and the status is read back through the show endpoint after every step.
 */
final class InvoiceLifecycleTest extends TestCase
{
    use ValidatesOpenApiContract;

    private const string CUSTOMER_EMAIL = 'jane@example.com';

    private const array PAYLOAD = [
        'customer_name' => 'Jane Doe',
        'customer_email' => self::CUSTOMER_EMAIL,
        'product_lines' => [
            ['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 15000],
            ['name' => 'Hosting', 'quantity' => 3, 'unit_price' => 100],
        ],
    ];

    private FakeNotificationFacade $notifications;

    private MockInterface $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommittedOpenApiDocument();
        $this->log = Log::spy();
        $this->app->instance(NotificationFacadeInterface::class, $this->notifications = new FakeNotificationFacade);
    }

    public function test_invoice_goes_from_draft_to_sent_to_client(): void
    {
        $invoiceId = $this->createInvoice();
        $this->assertStatus($invoiceId, 'draft');

        $this->sendInvoice($invoiceId);
        $this->assertStatus($invoiceId, 'sending');

        self::assertCount(1, $this->notifications->notified);
        $notification = $this->notifications->notified[0];
        self::assertSame($invoiceId, $notification->resourceId->toString());
        self::assertSame(self::CUSTOMER_EMAIL, $notification->toEmail);
        self::assertSame(sprintf('Invoice %s', $invoiceId), $notification->subject);
        self::assertSame(sprintf('Your invoice %s for a total of 303.00 is ready.', $invoiceId), $notification->message);

        $this->deliver($notification->resourceId->toString());

        $response = $this->getJson(route('invoices.show', $invoiceId));
        $response->assertOk()->assertJson(static fn (AssertableJson $json): AssertableJson => $json
            ->has('data', static fn (AssertableJson $data): AssertableJson => $data
                ->where('id', $invoiceId)
                ->where('status', 'sent-to-client')
                ->where('customer_name', 'Jane Doe')
                ->where('customer_email', self::CUSTOMER_EMAIL)
                ->has('product_lines', 2)
                ->has('product_lines.0', static fn (AssertableJson $line): AssertableJson => $line
                    ->whereType('id', 'string')
                    ->where('name', 'Consulting')
                    ->where('quantity', 2)
                    ->where('unit_price', 15000)
                    ->where('total_unit_price', 30000))
                ->has('product_lines.1', static fn (AssertableJson $line): AssertableJson => $line
                    ->whereType('id', 'string')
                    ->where('name', 'Hosting')
                    ->where('quantity', 3)
                    ->where('unit_price', 100)
                    ->where('total_unit_price', 300))
                ->where('total_price', 30300)));
        $this->assertMatchesContract($response, 200);

        self::assertCount(1, $this->notifications->notified);
        $this->log->shouldNotHaveReceived('warning');
    }

    public function test_delivery_before_send_does_not_advance_invoice(): void
    {
        $invoiceId = $this->createInvoice();

        $this->deliver($invoiceId);

        $this->assertStatus($invoiceId, 'draft');
        $this->log->shouldHaveReceived('warning', ['Ignoring delivery webhook for invoice', [
            'invoice_id' => $invoiceId,
            'reason' => sprintf('Invoice %s cannot move from draft to sent-to-client.', $invoiceId),
        ]]);
        $warnings = $this->log->shouldHaveReceived('warning');
        self::assertInstanceOf(VerificationDirector::class, $warnings);
        $warnings->once();

        $this->sendInvoice($invoiceId);
        $this->assertStatus($invoiceId, 'sending');

        $this->deliver($invoiceId);
        $this->assertStatus($invoiceId, 'sent-to-client');
    }

    public function test_failed_notification_can_be_retried_to_sent_to_client(): void
    {
        $invoiceId = $this->createInvoice();
        $this->notifications->failWith = new RuntimeException('smtp down');

        $failed = $this->postJson(route('invoices.send', $invoiceId));

        $failed->assertServiceUnavailable();
        $this->assertMatchesContract($failed, 503);
        $this->assertStatus($invoiceId, 'draft');

        $this->notifications->failWith = null;
        $this->sendInvoice($invoiceId);
        $this->assertStatus($invoiceId, 'sending');
        self::assertCount(1, $this->notifications->notified);

        $this->deliver($this->notifications->notified[0]->resourceId->toString());
        $this->assertStatus($invoiceId, 'sent-to-client');

        $this->log->shouldNotHaveReceived('warning');
    }

    private function createInvoice(): string
    {
        $response = $this->postJson(route('invoices.store'), self::PAYLOAD);

        $response->assertCreated();
        $this->assertMatchesContract($response, 201);

        $invoiceId = $response->json('data.id');
        self::assertIsString($invoiceId);

        return $invoiceId;
    }

    private function sendInvoice(string $invoiceId): void
    {
        $response = $this->postJson(route('invoices.send', $invoiceId));

        $response->assertOk()->assertJsonPath('data.status', 'sending');
        $this->assertMatchesContract($response, 200);
    }

    private function deliver(string $reference): void
    {
        $response = $this->getJson(route('notification.hook', ['action' => 'delivered', 'reference' => $reference]));

        $response->assertNoContent();
        $this->assertMatchesContract($response, 204);
    }

    private function assertStatus(string $invoiceId, string $expected): void
    {
        $response = $this->getJson(route('invoices.show', $invoiceId));

        $response->assertOk()->assertJsonPath('data.status', $expected);
        $this->assertMatchesContract($response, 200);
    }
}
