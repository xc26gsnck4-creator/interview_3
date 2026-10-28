<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice\Http;

use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Modules\Invoices\Application\Exceptions\NotificationFailed;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Notifications\Api\NotificationFacadeInterface;
use RuntimeException;
use Tests\Support\FakeNotificationFacade;
use Tests\TestCase;

final class SendInvoiceTest extends TestCase
{
    private FakeNotificationFacade $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(NotificationFacadeInterface::class, $this->notifications = new FakeNotificationFacade);
    }

    public function test_sends_draft_invoice_with_lines(): void
    {
        $invoice = Invoice::factory()->withLines()->create(['customer_email' => 'jane@example.com']);

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertOk()
            ->assertJsonPath('data.id', $invoice->id)
            ->assertJsonPath('data.status', 'sending');
        self::assertCount(1, $this->notifications->notified);
        self::assertSame($invoice->id, $this->notifications->notified[0]->resourceId->toString());
        self::assertSame('jane@example.com', $this->notifications->notified[0]->toEmail);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sending']);
    }

    /**
     * The route accepts an uppercase uuid; ids are stored lowercase.
     */
    public function test_sends_invoice_by_uppercase_id(): void
    {
        $invoice = Invoice::factory()->withLines()->create();

        $response = $this->postJson(route('invoices.send', strtoupper($invoice->id)));

        $response->assertOk()->assertJsonPath('data.id', $invoice->id);
    }

    public function test_rejects_invoice_without_lines_with_422(): void
    {
        $invoice = Invoice::factory()->create();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertUnprocessable()
            ->assertExactJson(['message' => sprintf('Invoice %s cannot be sent without product lines.', $invoice->id)]);
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    /**
     * A client error, so it is not reported.
     */
    public function test_second_send_is_rejected_with_409(): void
    {
        $exceptions = Exceptions::fake();
        $invoice = Invoice::factory()->withLines()->create();

        $first = $this->postJson(route('invoices.send', $invoice->id));
        $second = $this->postJson(route('invoices.send', $invoice->id));

        $first->assertOk();
        $second->assertConflict()->assertExactJson([
            'message' => sprintf('Invoice %s cannot move to sending: its status is sending, not draft.', $invoice->id),
        ]);
        self::assertCount(1, $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sending']);
        $exceptions->assertNotReported(InvalidInvoiceStatusTransition::class);
    }

    public function test_returns_404_for_unknown_invoice(): void
    {
        $unknownId = (string) Str::uuid7();

        $response = $this->postJson(route('invoices.send', $unknownId));

        $response->assertNotFound()->assertExactJson(['message' => sprintf('Invoice %s was not found.', $unknownId)]);
    }

    /**
     * The exact body proves the notification's own message stays out.
     */
    public function test_answers_503_and_stays_draft_when_the_notification_fails(): void
    {
        $exceptions = Exceptions::fake();
        $this->notifications->failWith = new RuntimeException('smtp down');
        $invoice = Invoice::factory()->withLines()->create();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertServiceUnavailable()->assertExactJson([
            'message' => sprintf('Invoice %s could not be sent because the notification failed; it remains in draft and the send can be retried.', $invoice->id),
        ]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $exceptions->assertReported(NotificationFailed::class);
    }
}
