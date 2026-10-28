<?php

declare(strict_types=1);

namespace Tests\Feature\Invoice;

use Modules\Invoices\Domain\Models\Invoice;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Modules\Notifications\Infrastructure\Drivers\DummyDriver;
use Tests\Support\FakeNotificationDriver;
use Tests\TestCase;

/**
 * One send through the real NotificationFacade and NotificationServiceProvider,
 * with only the driver replaced by a fake that records what it was given. The
 * fake is bound under DummyDriver, the class the provider maps DriverInterface
 * to, so both of the provider's bindings are exercised. The other send tests
 * fake the facade itself, so this is the one place where a wrong argument in
 * the facade or a binding missing from the provider's register() shows. The
 * provider is deferred, but the test bootstrap runs the console kernel, which
 * loads every deferred provider, so no later register() drops the fake; for the
 * same reason an entry missing from provides() would not show here.
 * DeliveryWebhookTest covers the other direction, Notifications to Invoices.
 */
final class SendInvoiceNotificationTest extends TestCase
{
    public function test_send_hands_the_notification_to_the_driver_through_the_real_facade(): void
    {
        $this->app->instance(DummyDriver::class, $driver = new FakeNotificationDriver);
        $invoice = Invoice::factory()->create(['customer_email' => 'jane@example.com']);
        InvoiceProductLine::factory()->for($invoice)->create(['quantity' => 2, 'unit_price' => 6001]);

        $response = $this->postJson(route('invoices.send', $invoice->id));

        $response->assertOk()->assertJsonPath('data.status', 'sending');
        self::assertSame([[
            'toEmail' => 'jane@example.com',
            'subject' => sprintf('Invoice %s', $invoice->id),
            'message' => sprintf('Your invoice %s for a total of 120.02 is ready.', $invoice->id),
            'reference' => $invoice->id,
        ]], $driver->sent);
        $this->assertDatabaseHas('invoices', ['id' => $invoice->id, 'status' => 'sending']);
    }
}
