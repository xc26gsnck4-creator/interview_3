<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Services;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Iterator;
use Modules\Invoices\Application\Services\MarkInvoiceDeliveredService;
use Modules\Invoices\Domain\Enums\StatusEnum;
use Modules\Invoices\Domain\Exceptions\InvalidInvoiceStatusTransition;
use Modules\Invoices\Domain\Exceptions\InvoiceNotFound;
use Modules\Invoices\Domain\Models\Invoice;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MarkInvoiceDeliveredServiceTest extends TestCase
{
    public function test_moves_sending_invoice_to_sent_to_client(): void
    {
        $invoice = Invoice::factory()->withLines()->sending()->create();

        $delivered = $this->service()->handle($invoice->id);

        self::assertSame($invoice->id, $delivered->id);
        self::assertSame(StatusEnum::SentToClient, $delivered->status);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sent-to-client']);
    }

    public function test_throws_not_found_for_unknown_id(): void
    {
        Invoice::factory()->withLines()->sending()->create();
        $unknownId = (string) Str::uuid7();

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($unknownId),
            InvoiceNotFound::class,
            sprintf('Invoice %s was not found.', $unknownId),
        );
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
    public function test_throws_transition_when_not_sending(StatusEnum $status): void
    {
        $invoice = Invoice::factory()->withLines()->inStatus($status)->create();

        $this->assertThrows(
            fn (): Invoice => $this->service()->handle($invoice->id),
            InvalidInvoiceStatusTransition::class,
            sprintf('Invoice %s cannot move from %s to sent-to-client.', $invoice->id, $status->value),
        );
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => $status->value]);
    }

    /**
     * The lookup must not read outside the transaction: a send holding the
     * write lock would still show the invoice as draft. RefreshDatabase wraps
     * the test in a transaction, so the service's own is one level deeper.
     */
    public function test_runs_the_lookup_and_the_claim_in_its_own_transaction(): void
    {
        $invoice = Invoice::factory()->withLines()->sending()->create();
        $testLevel = DB::transactionLevel();
        $queryLevels = [];
        DB::listen(static function (QueryExecuted $query) use (&$queryLevels): void {
            $queryLevels[] = $query->connection->transactionLevel();
        });

        $this->service()->handle($invoice->id);

        self::assertSame([$testLevel + 1, $testLevel + 1], $queryLevels);
    }

    private function service(): MarkInvoiceDeliveredService
    {
        return $this->app->make(MarkInvoiceDeliveredService::class);
    }
}
