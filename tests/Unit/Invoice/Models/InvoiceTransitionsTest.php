<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Models;

use Illuminate\Support\Facades\DB;
use Iterator;
use Modules\Invoices\Domain\Enums\InvoiceStatus;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceNotSendable;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Modules\Invoices\Domain\ValueObjects\Amount;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

final class InvoiceTransitionsTest extends TestCase
{
    public function test_ensure_can_be_sent_passes_for_draft_with_valid_lines(): void
    {
        $max = Amount::MAX;
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->for($invoice)->forEachSequence(
            ['quantity' => 1, 'unit_price' => $max],
            ['quantity' => $max, 'unit_price' => 1],
        )->create();

        $invoice->ensureCanBeSent();

        self::assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    public function test_ensure_can_be_sent_throws_not_sendable_for_draft_without_lines(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertThrows(
            fn () => $invoice->ensureCanBeSent(),
            InvoiceNotSendable::class,
            sprintf('Invoice %s cannot be sent without product lines.', $invoice->id),
        );
    }

    /**
     * @return Iterator<string, array{'quantity'|'unit_price', float|string}>
     */
    public static function invalidPersistedValues(): Iterator
    {
        yield 'text unit price' => ['unit_price', 'abc'];
        yield 'fractional quantity' => ['quantity', 1.5];
    }

    /**
     * The row is written with the query builder, past the cast, so
     * the send rule is proven to hold on its own. The invalid line comes after
     * a valid one, so every line is checked, not only the first.
     */
    #[DataProvider('invalidPersistedValues')]
    public function test_ensure_can_be_sent_throws_not_sendable_when_a_persisted_line_is_invalid(string $field, float|string $value): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $line = InvoiceProductLine::factory()->for($invoice)->create();
        DB::table('invoice_product_lines')->where('id', $line->id)->update([$field => $value]);

        $this->assertThrows(
            fn () => $invoice->ensureCanBeSent(),
            InvoiceNotSendable::class,
            sprintf('Invoice %s cannot be sent: a product line has an invalid quantity or unit price.', $invoice->id),
        );
    }

    /**
     * @return Iterator<string, array{InvoiceStatus}>
     */
    public static function statusesOtherThanDraft(): Iterator
    {
        yield 'sending' => [InvoiceStatus::Sending];
        yield 'sent to client' => [InvoiceStatus::SentToClient];
    }

    /**
     * The invoice has no lines, so the status check is proven to run first.
     */
    #[DataProvider('statusesOtherThanDraft')]
    public function test_ensure_can_be_sent_throws_transition_when_not_draft(InvoiceStatus $status): void
    {
        $invoice = Invoice::factory()->inStatus($status)->create();

        $this->assertThrows(
            fn () => $invoice->ensureCanBeSent(),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move to sending: its status is %s, not draft.', $invoice->id, $status->value),
        );
    }

    public function test_mark_as_sending_from_draft_persists_sending(): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $this->travel(1)->minute();

        $invoice->markAsSending();

        $stored = Invoice::query()->findOrFail($invoice->id);
        self::assertSame(InvoiceStatus::Sending, $invoice->status);
        self::assertSame(InvoiceStatus::Sending, $stored->status);
        self::assertSame(now()->toDateTimeString(), $stored->updated_at?->toDateTimeString());
        self::assertSame(now()->toDateTimeString(), $invoice->updated_at?->toDateTimeString());
        self::assertFalse($invoice->isDirty());
    }

    #[DataProvider('statusesOtherThanDraft')]
    public function test_mark_as_sending_throws_when_not_draft(InvoiceStatus $status): void
    {
        $invoice = Invoice::factory()->inStatus($status)->create();

        $this->assertThrows(
            fn () => $invoice->markAsSending(),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move to sending: its status is %s, not draft.', $invoice->id, $status->value),
        );
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status->value]);
    }

    /**
     * The send rule is part of the transition, so no caller can claim a draft
     * that ensureCanBeSent() would reject.
     */
    public function test_mark_as_sending_rejects_a_draft_that_cannot_be_sent(): void
    {
        $invoice = Invoice::factory()->create();

        $this->assertThrows(
            fn () => $invoice->markAsSending(),
            InvoiceNotSendable::class,
            sprintf('Invoice %s cannot be sent without product lines.', $invoice->id),
        );
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    public function test_mark_as_sent_to_client_from_sending_persists(): void
    {
        $invoice = Invoice::factory()->sending()->create();
        $this->travel(1)->minute();

        $invoice->markAsSentToClient();

        $stored = Invoice::query()->findOrFail($invoice->id);
        self::assertSame(InvoiceStatus::SentToClient, $invoice->status);
        self::assertSame(InvoiceStatus::SentToClient, $stored->status);
        self::assertSame(now()->toDateTimeString(), $stored->updated_at?->toDateTimeString());
        self::assertSame(now()->toDateTimeString(), $invoice->updated_at?->toDateTimeString());
        self::assertFalse($invoice->isDirty());
    }

    /**
     * @return Iterator<string, array{InvoiceStatus}>
     */
    public static function statusesOtherThanSending(): Iterator
    {
        yield 'draft' => [InvoiceStatus::Draft];
        yield 'sent to client' => [InvoiceStatus::SentToClient];
    }

    #[DataProvider('statusesOtherThanSending')]
    public function test_mark_as_sent_to_client_throws_when_not_sending(InvoiceStatus $status): void
    {
        $invoice = Invoice::factory()->inStatus($status)->create();

        $this->assertThrows(
            fn () => $invoice->markAsSentToClient(),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move to sent-to-client: its status is %s, not sending.', $invoice->id, $status->value),
        );
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status->value]);
    }

    /**
     * The second instance is stale: it still holds draft in memory after the
     * first claimed the row, so only the conditional update can stop it. In the
     * application, IMMEDIATE transactions keep a concurrent send or delivery
     * from reading a stale status; this guard, for both transitions, holds in
     * any transaction mode. It reports the status it found and takes it over.
     */
    public function test_mark_as_sending_claims_the_row_only_once(): void
    {
        $first = Invoice::factory()->withLines()->create();
        $second = Invoice::query()->findOrFail($first->id);

        $first->markAsSending();

        $this->assertThrows(
            fn () => $second->markAsSending(),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move to sending: its status is sending, not draft.', $first->id),
        );
        self::assertSame(InvoiceStatus::Sending, $second->status);
        self::assertSame(InvoiceStatus::Sending, $first->fresh()?->status);
    }

    public function test_mark_as_sent_to_client_claims_the_row_only_once(): void
    {
        $first = Invoice::factory()->sending()->create();
        $second = Invoice::query()->findOrFail($first->id);

        $first->markAsSentToClient();

        $this->assertThrows(
            fn () => $second->markAsSentToClient(),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move to sent-to-client: its status is sent-to-client, not sending.', $first->id),
        );
        self::assertSame(InvoiceStatus::SentToClient, $second->status);
        self::assertSame(InvoiceStatus::SentToClient, $first->fresh()?->status);
    }

    /**
     * A lost claim takes over only the status the row holds; the instance's
     * other unsaved changes stay as they were.
     */
    public function test_lost_claim_updates_only_the_status_of_the_instance(): void
    {
        $first = Invoice::factory()->withLines()->create();
        $second = Invoice::query()->findOrFail($first->id);
        $second->customer_name = 'Unsaved';

        $first->markAsSending();

        $this->assertThrows(fn () => $second->markAsSending(), InvalidInvoiceStatusTransition::class);
        self::assertSame(InvoiceStatus::Sending, $second->status);
        self::assertSame(['customer_name' => 'Unsaved'], $second->getDirty());
    }

    /**
     * A failed send rolls its claim back, but the instance keeps sending in
     * memory. The in-memory check refuses it before any write, so a stale
     * instance has to be reloaded before it can be sent again.
     */
    public function test_mark_as_sending_refuses_an_instance_left_stale_by_a_rolled_back_claim(): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $this->assertThrows(
            fn () => DB::transaction(static function () use ($invoice): never {
                $invoice->markAsSending();

                throw new RuntimeException('Notification failed.');
            }),
            RuntimeException::class,
        );

        $this->assertThrows(
            fn () => $invoice->markAsSending(),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move to sending: its status is sending, not draft.', $invoice->id),
        );
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
    }

    /**
     * The same in-memory check guards the delivery transition: a rolled-back
     * claim leaves the instance at sent-to-client while the row is sending.
     */
    public function test_mark_as_sent_to_client_refuses_an_instance_left_stale_by_a_rolled_back_claim(): void
    {
        $invoice = Invoice::factory()->sending()->create();
        $this->assertThrows(
            fn () => DB::transaction(static function () use ($invoice): never {
                $invoice->markAsSentToClient();

                throw new RuntimeException('Delivery handling failed.');
            }),
            RuntimeException::class,
        );

        $this->assertThrows(
            fn () => $invoice->markAsSentToClient(),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move to sent-to-client: its status is sent-to-client, not sending.', $invoice->id),
        );
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sending']);
    }

    public function test_invoice_can_be_saved_after_a_transition(): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $invoice->markAsSending();

        $invoice->customer_name = 'Renamed';
        $invoice->save();

        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'customer_name' => 'Renamed', 'status' => 'sending']);
    }
}
