<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Listeners;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Iterator;
use Modules\Invoices\Application\Services\MarkInvoiceDeliveredService;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Infrastructure\Listeners\WebhookDeliveredListener;
use Modules\Notifications\Api\Events\WebhookDeliveredEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;
use ValueError;

final class WebhookDeliveredListenerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function test_marks_sending_invoice_delivered(): void
    {
        $invoice = Invoice::factory()->withLines()->sending()->create();
        $this->logger->expects($this->never())->method(self::anything());

        $this->listener()->handle($this->deliveredEvent($invoice->id));

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent-to-client']);
    }

    public function test_logs_and_ignores_unknown_invoice(): void
    {
        $unknownId = (string) Str::uuid7();
        $this->logger->expects($this->once())->method('warning')->with('Ignoring delivery webhook for invoice', [
            'invoice_id' => $unknownId,
            'reason' => sprintf('Invoice %s was not found.', $unknownId),
        ]);

        $this->listener()->handle($this->deliveredEvent($unknownId));
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
    public function test_logs_and_ignores_invoice_not_in_sending(StatusEnum $status): void
    {
        $invoice = Invoice::factory()->withLines()->inStatus($status)->create();
        $this->logger->expects($this->once())->method('warning')->with('Ignoring delivery webhook for invoice', [
            'invoice_id' => $invoice->id,
            'reason' => sprintf('Invoice %s cannot move from %s to sent-to-client.', $invoice->id, $status->value),
        ]);

        $this->listener()->handle($this->deliveredEvent($invoice->id));

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status->value]);
    }

    /**
     * Only the two expected rejections are tolerated. A row written past the
     * model with a status outside StatusEnum fails the enum cast, and that
     * error reaches the webhook instead of being logged as an ignored report.
     */
    public function test_lets_an_unexpected_error_propagate_without_a_warning(): void
    {
        $invoice = Invoice::factory()->withLines()->sending()->create();
        DB::table('invoices')->where('id', $invoice->id)->update(['status' => 'archived']);
        $this->logger->expects($this->never())->method(self::anything());

        $this->assertThrows(
            function () use ($invoice): void {
                $this->listener()->handle($this->deliveredEvent($invoice->id));
            },
            ValueError::class,
            '"archived" is not a valid backing value',
        );
    }

    private function listener(): WebhookDeliveredListener
    {
        return new WebhookDeliveredListener($this->app->make(MarkInvoiceDeliveredService::class), $this->logger);
    }

    private function deliveredEvent(string $invoiceId): WebhookDeliveredEvent
    {
        return new WebhookDeliveredEvent(Uuid::fromString($invoiceId));
    }
}
