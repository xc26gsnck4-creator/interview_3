<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Services;

use Error;
use Illuminate\Support\Str;
use Iterator;
use Modules\Invoices\Application\Exceptions\NotificationFailed;
use Modules\Invoices\Application\Services\SendInvoiceService;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Exceptions\InvoiceNotSendable;
use Modules\Invoices\Domain\Exceptions\InvoiceTotalOverflow;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Modules\Notifications\Api\NotificationFacadeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeNotificationFacade;
use Tests\TestCase;
use Throwable;

final class SendInvoiceServiceTest extends TestCase
{
    private FakeNotificationFacade $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->notifications = new FakeNotificationFacade;
        $this->app->instance(NotificationFacadeInterface::class, $this->notifications);
    }

    public function test_notifies_customer_and_marks_invoice_sending(): void
    {
        $invoice = Invoice::factory()->create(['customer_email' => 'billing@acme.test']);
        InvoiceProductLine::factory()->for($invoice)->forEachSequence(
            ['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 6001],
            ['name' => 'Hosting', 'quantity' => 3, 'unit_price' => 101],
        )->create();

        $sent = $this->service()->handle($invoice->id);

        self::assertCount(1, $this->notifications->notified);
        $notification = $this->notifications->notified[0];
        self::assertSame($invoice->id, $notification->resourceId->toString());
        self::assertSame('billing@acme.test', $notification->toEmail);
        self::assertSame(sprintf('Invoice %s', $invoice->id), $notification->subject);
        self::assertSame(sprintf('Your invoice %s for a total of 123.05 is ready.', $invoice->id), $notification->message);

        self::assertSame($invoice->id, $sent->id);
        self::assertSame(StatusEnum::Sending, $sent->status);
        self::assertTrue($sent->relationLoaded('productLines'));
        self::assertSame(['Consulting', 'Hosting'], $sent->productLines->pluck('name')->all());
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sending']);
    }

    public function test_throws_not_found_for_unknown_id(): void
    {
        Invoice::factory()->withLines()->create();
        $unknownId = (string) Str::uuid7();

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($unknownId),
            InvoiceNotFound::class,
            sprintf('Invoice %s was not found.', $unknownId),
        );
        self::assertSame([], $this->notifications->notified);
    }

    public function test_rejects_draft_without_lines_and_does_not_notify(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($invoice->id),
            InvoiceNotSendable::class,
            sprintf('Invoice %s cannot be sent without product lines.', $invoice->id),
        );
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    /**
     * @return Iterator<string, array{StatusEnum}>
     */
    public static function statusesOtherThanDraft(): Iterator
    {
        yield 'sending' => [StatusEnum::Sending];
        yield 'sent to client' => [StatusEnum::SentToClient];
    }

    #[DataProvider('statusesOtherThanDraft')]
    public function test_rejects_non_draft_and_does_not_notify(StatusEnum $status): void
    {
        $invoice = Invoice::factory()->withLines()->inStatus($status)->create();

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($invoice->id),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move from %s to sending.', $invoice->id, $status->value),
        );
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status->value]);
    }

    /**
     * @return Iterator<string, array{Throwable}>
     */
    public static function notificationFailures(): Iterator
    {
        yield 'exception' => [new RuntimeException('smtp down')];
        yield 'error' => [new Error('boom')];
    }

    #[DataProvider('notificationFailures')]
    public function test_rolls_back_to_draft_when_the_notification_fails(Throwable $failure): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $this->notifications->failWith = $failure;

        try {
            $this->service()->handle($invoice->id);
            self::fail('Expected NotificationFailed');
        } catch (NotificationFailed $notificationFailed) {
            self::assertSame($failure, $notificationFailed->getPrevious());
            self::assertSame(
                sprintf('Invoice %s could not be sent because the notification failed; it remains in draft and the send can be retried.', $invoice->id),
                $notificationFailed->getMessage(),
            );
        }

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    /**
     * Each line is valid, but the sum of three MAX_VALUE x MAX_VALUE lines
     * exceeds PHP's int, so the message cannot be built after the claim.
     */
    public function test_rolls_back_to_draft_when_the_total_overflows(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->count(3)->for($invoice)->create([
            'quantity' => InvoiceProductLine::MAX_VALUE,
            'unit_price' => InvoiceProductLine::MAX_VALUE,
        ]);

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($invoice->id),
            InvoiceTotalOverflow::class,
            sprintf('The total of invoice %s exceeds the maximum representable integer.', $invoice->id),
        );
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    private function service(): SendInvoiceService
    {
        return $this->app->make(SendInvoiceService::class);
    }
}
