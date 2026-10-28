<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Modules\Notifications\Infrastructure\Drivers\DriverInterface;
use Tests\Support\FakeNotificationDriver;
use Tests\TestCase;

/**
 * The send endpoint through the real NotificationFacade and its provider,
 * with only the driver replaced by a fake that records what it was given. The
 * other tests fake the facade itself, so this is the one place where a wrong
 * argument in the facade or a lost provider binding shows. DeliveryWebhookTest
 * covers the other direction, Notifications to Invoices.
 */
final class SendInvoiceNotificationTest extends TestCase
{
    /**
     * The provider is deferred, but the test bootstrap runs the console
     * kernel, which loads every deferred provider, so its bindings are in
     * place before the driver's is replaced.
     */
    public function test_send_hands_the_notification_to_the_driver_through_the_real_facade(): void
    {
        $this->app->instance(DriverInterface::class, $driver = new FakeNotificationDriver);
        $invoice = Invoice::factory()->create(['customer_email' => 'jane@example.com']);
        InvoiceProductLine::factory()->for($invoice)->forEachSequence(
            ['name' => 'Consulting', 'quantity' => 2, 'unit_price' => 6001],
            ['name' => 'Hosting', 'quantity' => 3, 'unit_price' => 101],
        )->create();

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertOk()->assertJsonPath('data.status', 'sending');
        self::assertSame([[
            'toEmail' => 'jane@example.com',
            'subject' => sprintf('Invoice %s', $invoice->id),
            'message' => sprintf('Your invoice %s for a total of 123.05 is ready.', $invoice->id),
            'reference' => $invoice->id,
        ]], $driver->sent);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sending']);
    }
}
