<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Override;

final class CreateInvoiceRequest extends FormRequest
{
    /**
     * Bounds the work one request does: the count is checked before the
     * per-line rules are built, and every line is inserted and read back while
     * SQLite's write lock is held.
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
     * trims the strings of a JSON body and turns empty ones into null only when
     * the request is marked as JSON, which the rules rely on.
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
     * The shape of product_lines is checked first, on its own. Laravel expands
     * the per-line rules over every submitted line before any rule runs, so
     * its own rules (a list of at most MAX_PRODUCT_LINES) run before the lines
     * are validated. JSON is decoded into PHP arrays, where `{}` and
     * `{"0": ...}` pass as lists, so the raw body of a JSON request is decoded
     * once more to reject an object, as the `list` rule would.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $validator = validator($this->validationData(), ['product_lines' => $this->rules()['product_lines']]);
        $validator->after(function (Validator $validator): void {
            $body = $this->isJson() ? json_decode($this->getContent()) : null;

            if (is_object($body) && property_exists($body, 'product_lines') && is_object($body->product_lines)) {
                $validator->addFailure('product_lines', 'list');
            }
        });
        $validator->validate();
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
