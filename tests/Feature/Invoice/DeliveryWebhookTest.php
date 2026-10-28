<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Modules\Invoices\Domain\Models\Invoice;
use Tests\TestCase;

/**
 * The real webhook route and real event dispatch: the Notifications module
 * dispatches WebhookDeliveredEvent and the Invoices listener handles it within
 * the same request. The webhook answers 204 whatever the invoice's state.
 */
final class DeliveryWebhookTest extends TestCase
{
    private MockInterface $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = Log::spy();
    }

    public function test_delivered_webhook_moves_sending_invoice_to_sent_to_client(): void
    {
        $invoice = Invoice::factory()->withLines()->sending()->create();

        $response = $this->getJson($this->deliveredHook($invoice->id));

        $response->assertNoContent();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent-to-client']);
        $this->log->shouldNotHaveReceived('warning');
    }

    public function test_delivered_webhook_for_invoice_not_in_sending_is_ignored_with_warning(): void
    {
        $invoice = Invoice::factory()->withLines()->sentToClient()->create();

        $response = $this->getJson($this->deliveredHook($invoice->id));

        $response->assertNoContent();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent-to-client']);
        $this->log->shouldHaveReceived('warning', ['Ignoring delivery webhook for invoice', [
            'invoice_id' => $invoice->id,
            'reason' => sprintf('Invoice %s cannot move to sent-to-client: its status is sent-to-client, not sending.', $invoice->id),
        ]]);
    }

    public function test_delivered_webhook_for_unknown_invoice_answers_no_content(): void
    {
        $unknownId = (string) Str::uuid7();

        $response = $this->getJson($this->deliveredHook($unknownId));

        $response->assertNoContent();

        $this->log->shouldHaveReceived('warning', ['Ignoring delivery webhook for invoice', [
            'invoice_id' => $unknownId,
            'reason' => sprintf('Invoice %s was not found.', $unknownId),
        ]]);
    }

    /**
     * The route accepts an uppercase uuid; ids are stored lowercase.
     */
    public function test_delivered_webhook_with_uppercase_reference_moves_invoice_to_sent_to_client(): void
    {
        $invoice = Invoice::factory()->withLines()->sending()->create();

        $response = $this->getJson($this->deliveredHook(strtoupper($invoice->id)));

        $response->assertNoContent();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent-to-client']);
    }

    private function deliveredHook(string $reference): string
    {
        return route('notification.hook', ['action' => 'delivered', 'reference' => $reference]);
    }
}
