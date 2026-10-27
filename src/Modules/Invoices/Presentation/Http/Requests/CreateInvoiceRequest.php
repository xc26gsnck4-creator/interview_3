<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Domain\ValueObjects\Amount;
use Override;

final class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The API takes JSON only: the query string and any body not marked as JSON
     * are never read, so bytes that are not UTF-8 cannot reach the database.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : [];
    }

    /**
     * `array` is redundant beside `list` but stays for the API document:
     * Scramble writes the array type and `maxItems` only for it.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $amount = ['required', 'integer:strict', 'min:1', 'max:'.Amount::MAX];

        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'product_lines' => ['nullable', 'array', 'list', 'max:1000'],
            'product_lines.*.name' => ['required', 'string', 'max:255'],
            'product_lines.*.quantity' => $amount,
            'product_lines.*.unit_price' => $amount,
        ];
    }

    /**
     * Reads the validated fields by name, so keys a line carries beyond them
     * are ignored.
     */
    public function toData(): CreateInvoiceData
    {
        $validated = $this->safe();

        return new CreateInvoiceData(
            customerName: $validated->string('customer_name')->toString(),
            customerEmail: $validated->string('customer_email')->toString(),
            productLines: array_map(
                static fn (int|string $index): ProductLineData => new ProductLineData(
                    name: $validated->string("product_lines.{$index}.name")->toString(),
                    quantity: $validated->integer("product_lines.{$index}.quantity"),
                    unitPrice: $validated->integer("product_lines.{$index}.unit_price"),
                ),
                array_keys($validated->array('product_lines')),
            ),
        );
    }
}
