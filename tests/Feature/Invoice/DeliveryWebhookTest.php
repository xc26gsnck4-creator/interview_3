<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Iterator;
use Mockery\MockInterface;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Models\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return Iterator<string, array{StatusEnum}>
     */
    public static function statusesOtherThanSending(): Iterator
    {
        yield 'draft' => [StatusEnum::Draft];
        yield 'sent to client' => [StatusEnum::SentToClient];
    }

    #[DataProvider('statusesOtherThanSending')]
    public function test_delivered_webhook_for_invoice_not_in_sending_is_ignored_with_warning(StatusEnum $status): void
    {
        $invoice = Invoice::factory()->withLines()->inStatus($status)->create();

        $response = $this->getJson($this->deliveredHook($invoice->id));

        $response->assertNoContent();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status->value]);
        $this->log->shouldHaveReceived('warning', ['Ignoring delivery webhook for invoice', [
            'invoice_id' => $invoice->id,
            'reason' => sprintf('Invoice %s cannot move from %s to sent-to-client.', $invoice->id, $status->value),
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
