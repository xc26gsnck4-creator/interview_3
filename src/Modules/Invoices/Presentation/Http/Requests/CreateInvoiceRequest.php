<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Override;

final class CreateInvoiceRequest extends FormRequest
{
    /**
     * Bounds the work one request does: every line is validated, inserted and
     * read back while SQLite's write lock is held.
     */
    private const int MAX_PRODUCT_LINES = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * The API takes JSON only, so only the body of a request marked as JSON is
     * validated: the query string never fills in a field, a form or other body
     * is not read (so bytes that are not UTF-8 cannot reach the database; JSON
     * decoding rejects them), and a malformed body validates as empty. Laravel
     * trims strings and turns empty ones into null only in a JSON request,
     * which the rules rely on.
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function validationData(): array
    {
        return $this->isJson() ? $this->json()->all() : [];
    }

    /**
     * Amounts must be JSON integers (`integer:strict` rejects true, "2" and 1.0)
     * within the line invariant's range, so input the domain would reject is a
     * 422 here rather than a 500. A blank name is null by the time it is
     * validated (the global TrimStrings and ConvertEmptyStringsToNull middleware).
     * `list` implies `array`; `array` is there for the API document, where
     * Scramble applies the line cap (maxItems) only to a known array type. A line
     * that is not an object fails its field rules, so `product_lines.*` needs no
     * rule of its own (one would make validated() scan every rule key once per
     * line).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $amount = ['required', 'integer:strict', 'min:1', 'max:'.InvoiceProductLine::MAX_VALUE];

        return [
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_email' => ['required', 'email', 'max:255'],
            'product_lines' => ['array', 'list', 'max:'.self::MAX_PRODUCT_LINES],
            'product_lines.*.name' => ['required', 'string', 'max:255'],
            'product_lines.*.quantity' => $amount,
            'product_lines.*.unit_price' => $amount,
        ];
    }

    /**
     * Reads the validated fields by name, so keys a line carries beyond them
     * are ignored. An absent `product_lines` is an invoice without lines.
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
