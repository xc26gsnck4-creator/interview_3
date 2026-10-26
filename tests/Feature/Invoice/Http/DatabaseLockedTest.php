<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice\Http;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Modules\Invoices\Application\Exceptions\SendNotRecorded;
use Modules\Invoices\Domain\Models\Invoice;
use Modules\Notifications\Api\NotificationFacadeInterface;
use PDOException;
use Tests\Concerns\UsesSqliteFileDatabase;
use Tests\Support\FakeNotificationFacade;
use Tests\TestCase;

/**
 * Real SQLite locks over HTTP: the application runs on a temporary file, and a
 * second connection holds the lock the request needs for longer than the
 * file's 100 ms busy timeout.
 */
final class DatabaseLockedTest extends TestCase
{
    use UsesSqliteFileDatabase;

    private const string HOLDER = 'sqlite_file_holder';

    private const string READER = 'sqlite_file_reader';

    private const string BUSY_MESSAGE = 'The database is busy; retry the request.';

    private FakeNotificationFacade $notifications;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteFileDatabase();
        $this->app->instance(NotificationFacadeInterface::class, $this->notifications = new FakeNotificationFacade);
        Exceptions::fake();
    }

    protected function tearDown(): void
    {
        $this->dropSqliteFileDatabase();

        parent::tearDown();
    }

    /**
     * The send's BEGIN fails before it reads or notifies anything, so the 503
     * means the request can be retried.
     */
    public function test_send_answers_503_while_another_connection_holds_the_write_lock(): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $holder = $this->holdTheWriteLock();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertServiceUnavailable()->assertExactJson(['message' => self::BUSY_MESSAGE]);
        self::assertSame([], $this->notifications->notified);
        $holder->rollBack();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        Exceptions::assertReported(
            static fn (PDOException $exception): bool => str_contains($exception->getMessage(), 'database is locked'),
        );
    }

    public function test_create_answers_503_while_another_connection_holds_the_write_lock(): void
    {
        $holder = $this->holdTheWriteLock();

        $response = $this->postJson(route('invoices.store'), [
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'product_lines' => [['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 15000]],
        ]);

        $response->assertServiceUnavailable()->assertExactJson(['message' => self::BUSY_MESSAGE]);
        $holder->rollBack();
        $this->assertDatabaseEmpty('invoices');
    }

    /**
     * The reader's shared lock lets the send begin and notify, but its COMMIT
     * cannot get the exclusive lock, so the customer was notified while the
     * invoice stays draft: a 500, not the retryable 503.
     */
    public function test_send_whose_commit_fails_behind_a_reader_answers_500_not_recorded(): void
    {
        $invoice = Invoice::factory()->withLines()->create();
        $reader = $this->sqliteFileConnection(self::READER, 'DEFERRED');
        $reader->beginTransaction();
        $reader->table('invoices')->count();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertInternalServerError()->assertExactJson([
            'message' => sprintf('Invoice %s was sent to the customer, but the send could not be recorded; it remains in draft, and sending it again notifies the customer again.', $invoice->id),
        ]);
        self::assertCount(1, $this->notifications->notified);
        $reader->commit();
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'draft']);
        Exceptions::assertReported(SendNotRecorded::class);
    }

    /**
     * The holder writes inside its open transaction, so it holds the write
     * lock whatever its transaction mode.
     */
    private function holdTheWriteLock(): ConnectionInterface
    {
        $holder = $this->sqliteFileConnection(self::HOLDER, 'IMMEDIATE');
        $holder->beginTransaction();
        $holder->table('invoices')->insert([
            'id' => (string) Str::uuid7(),
            'customer_name' => 'Lock Holder',
            'customer_email' => 'holder@example.com',
            'status' => 'draft',
        ]);

        return $holder;
    }
}
