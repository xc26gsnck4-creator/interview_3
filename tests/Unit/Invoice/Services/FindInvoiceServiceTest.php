<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\Services;

use Modules\Invoices\Application\Services\FindInvoiceService;
use Modules\Invoices\Domain\Models\Invoice;
use Tests\TestCase;

final class FindInvoiceServiceTest extends TestCase
{
    public function test_returns_invoice_with_lines(): void
    {
        Invoice::factory()->withLines()->create();
        $invoice = Invoice::factory()->withLines(2)->create();

        $found = $this->service()->handle($invoice->id);

        self::assertSame($invoice->id, $found->id);
        self::assertTrue($found->relationLoaded('productLines'));
        self::assertSame(
            $invoice->productLines()->pluck('id')->all(),
            $found->productLines->pluck('id')->all(),
        );
    }

    private function service(): FindInvoiceService
    {
        return $this->app->make(FindInvoiceService::class);
    }
}
