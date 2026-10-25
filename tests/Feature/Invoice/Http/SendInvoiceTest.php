<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice\Http;

use Error;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Iterator;
use Modules\Invoices\Application\Exceptions\NotificationFailed;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Modules\Notifications\Api\NotificationFacadeInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\ValidatesOpenApiContract;
use Tests\Support\FakeNotificationFacade;
use Tests\TestCase;
use Throwable;

final class SendInvoiceTest extends TestCase
{
    use ValidatesOpenApiContract;

    private FakeNotificationFacade $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCommittedOpenApiDocument();
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
        $this->assertMatchesContract($response, 200);
    }

    public function test_runs_no_query_after_the_commit(): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $committed = false;
        $queriesAfterCommit = [];
        Event::listen(TransactionCommitted::class, static function () use (&$committed): void {
            $committed = true;
        });
        DB::listen(static function (QueryExecuted $query) use (&$committed, &$queriesAfterCommit): void {
            if ($committed) {
                $queriesAfterCommit[] = $query->sql;
            }
        });

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertOk();
        self::assertTrue($committed);
        self::assertSame([], $queriesAfterCommit);
        $this->assertMatchesContract($response, 200);
    }

    /**
     * The route accepts an uppercase uuid; ids are stored lowercase.
     */
    public function test_sends_invoice_by_uppercase_id(): void
    {
        $invoice = Invoice::factory()->withLines()->create();

        $response = $this->postJson(route('invoices.send', strtoupper($invoice->id)));

        $response->assertOk()->assertJsonPath('data.id', $invoice->id);
        $this->assertMatchesContract($response, 200);
    }

    public function test_rejects_invoice_without_lines_with_422(): void
    {
        $invoice = Invoice::factory()->create();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertUnprocessable()
            ->assertExactJson(['message' => sprintf('Invoice %s cannot be sent without product lines.', $invoice->id)]);
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $this->assertMatchesContract($response, 422);
    }

    public function test_rejects_invoice_with_invalid_persisted_line_with_422(): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        InvoiceProductLine::factory()->for($invoice)->createQuietly(['unit_price' => 0]);

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertUnprocessable()->assertExactJson([
            'message' => sprintf('Invoice %s cannot be sent: a product line has an invalid quantity or unit price.', $invoice->id),
        ]);
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $this->assertMatchesContract($response, 422);
    }

    public function test_rejects_invoice_whose_total_overflows_with_422(): void
    {
        $invoice = Invoice::factory()->create();
        InvoiceProductLine::factory()->count(3)->for($invoice)->create([
            'quantity' => InvoiceProductLine::MAX_VALUE,
            'unit_price' => InvoiceProductLine::MAX_VALUE,
        ]);

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertUnprocessable()
            ->assertExactJson(['message' => sprintf('Invoice %s cannot be sent: the total of its product lines exceeds 9223372036854775807.', $invoice->id)]);
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $this->assertMatchesContract($response, 422);
    }

    /**
     * A client error, so it is not reported.
     */
    #[DataProvider('nonDraftStatuses')]
    public function test_rejects_invoice_not_in_draft_with_409(StatusEnum $status): void
    {
        $exceptions = Exceptions::fake();
        $invoice = Invoice::factory()->withLines()->inStatus($status)->create();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertConflict()->assertExactJson([
            'message' => sprintf('Invoice %s cannot move from %s to sending.', $invoice->id, $status->value),
        ]);
        self::assertSame([], $this->notifications->notified);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status->value]);
        $exceptions->assertNotReported(InvalidInvoiceStatusTransition::class);
        $this->assertMatchesContract($response, 409);
    }

    /**
     * @return Iterator<string, array{StatusEnum}>
     */
    public static function nonDraftStatuses(): Iterator
    {
        yield 'sending' => [StatusEnum::Sending];
        yield 'sent to client' => [StatusEnum::SentToClient];
    }

    public function test_second_send_is_rejected_with_409(): void
    {
        $invoice = Invoice::factory()->withLines()->create();

        $first = $this->postJson(route('invoices.send', $invoice->id));
        $second = $this->postJson(route('invoices.send', $invoice->id));

        $first->assertOk();
        $second->assertConflict();
        self::assertCount(1, $this->notifications->notified);
        $this->assertMatchesContract($first, 200);
        $this->assertMatchesContract($second, 409);
    }

    public function test_returns_404_for_unknown_invoice(): void
    {
        $unknownId = (string) Str::uuid7();

        $response = $this->postJson(route('invoices.send', $unknownId));

        $response->assertNotFound()->assertExactJson(['message' => sprintf('Invoice %s was not found.', $unknownId)]);
        $this->assertMatchesContract($response, 404);
    }

    /**
     * The exact body proves the notification's own message stays out.
     */
    #[DataProvider('notificationFailures')]
    public function test_answers_503_and_stays_draft_when_the_notification_fails(Throwable $failure): void
    {
        $exceptions = Exceptions::fake();
        $this->notifications->failWith = $failure;
        $invoice = Invoice::factory()->withLines()->create();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertServiceUnavailable()->assertExactJson([
            'message' => sprintf('Invoice %s could not be sent because the notification failed; it remains in draft and the send can be retried.', $invoice->id),
        ]);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        $exceptions->assertReported(NotificationFailed::class);
        $this->assertMatchesContract($response, 503);
    }

    /**
     * @return Iterator<string, array{Throwable}>
     */
    public static function notificationFailures(): Iterator
    {
        yield 'runtime exception' => [new RuntimeException('smtp down')];
        yield 'error' => [new Error('smtp down')];
    }
}
