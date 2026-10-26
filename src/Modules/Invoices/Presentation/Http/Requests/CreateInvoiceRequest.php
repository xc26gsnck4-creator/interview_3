<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
use Modules\Invoices\Presentation\Http\Rules\MaxLengthAsSent;
use Override;

final class CreateInvoiceRequest extends FormRequest
{
    /**
     * Bounds the lines one request inserts and reads back while SQLite's write
     * lock is held; the count is checked before the per-line rules are built.
     * Validation itself stays linear in the body size (what a line carries
     * besides its fields is flattened too), as JSON decoding does.
     */
    private const int MAX_PRODUCT_LINES = 1000;

    /**
     * The length the API document states (`maxLength`) for the customer's name
     * and email and for a line's name.
     */
    private const int MAX_TEXT_LENGTH = 255;

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
     * validated (the global TrimStrings and ConvertEmptyStringsToNull middleware),
     * so `min:1` is there for the API document only (`minLength: 1`): at runtime
     * `required` plus the trimming middleware already reject an empty or blank
     * name. `list` implies `array`; `array` is there for the API document, where
     * Scramble applies the line cap (maxItems) only to a known array type. A line
     * that is not an object fails its field rules, so `product_lines.*` needs no
     * rule of its own (one would make validated() scan every rule key once per
     * line). A text field checks its length as sent (MaxLengthAsSent) last, and
     * `bail` stops the field at its first failure, so that check runs only on a
     * value the other rules accepted after trimming and a field reports one
     * message. `max:` is there for the API document (Scramble's `maxLength`):
     * at runtime, for a JSON body, the check of the length as sent already
     * rejects what `max:` would, because trimming only shortens a value, except
     * for a body the object decode rejects (see sentBody()), where only `max:`
     * measures the trimmed value; `max:` reports first when the trimmed value
     * is already too long.
     *
     * @return array<string, list<string|MaxLengthAsSent>>
     */
    public function rules(): array
    {
        $amount = ['required', 'integer:strict', 'min:1', 'max:'.InvoiceProductLine::MAX_VALUE];
        $lengthAsSent = new MaxLengthAsSent(self::MAX_TEXT_LENGTH, fn (): mixed => $this->sentBody());

        return [
            'customer_name' => ['bail', 'required', 'string', 'min:1', 'max:'.self::MAX_TEXT_LENGTH, $lengthAsSent],
            'customer_email' => ['bail', 'required', 'email', 'max:'.self::MAX_TEXT_LENGTH, $lengthAsSent],
            'product_lines' => ['array', 'list', 'max:'.self::MAX_PRODUCT_LINES],
            'product_lines.*.name' => ['bail', 'required', 'string', 'min:1', 'max:'.self::MAX_TEXT_LENGTH, $lengthAsSent],
            'product_lines.*.quantity' => $amount,
            'product_lines.*.unit_price' => $amount,
        ];
    }

    /**
     * The shape of product_lines is checked first, on its own. Laravel expands
     * the per-line rules over every submitted line before any rule runs, so
     * its own rules (a list of at most MAX_PRODUCT_LINES) run before the lines
     * are validated. Laravel decodes JSON into PHP arrays, where `{}` and
     * `{"0": ...}` pass as lists, so when the array rules accepted
     * product_lines the body as sent (sentBody(), objects kept) must show a
     * JSON array there. The check fails closed: a body that decodes only into
     * arrays (a key starting with NUL is no valid property name) reports the
     * `list` error too.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $validator = validator($this->validationData(), ['product_lines' => $this->rules()['product_lines']]);
        $validator->after(function (Validator $validator): void {
            if (! is_array($this->validationData()['product_lines'] ?? null)) {
                return;
            }

            $body = $this->sentBody();

            if (! is_object($body) || ! is_array(get_object_vars($body)['product_lines'])) {
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

    /**
     * The raw body decoded once per request (`once()`), keeping JSON objects as
     * objects so that `{}` and `[]` stay apart; null when the body does not
     * decode. It is decoded only when read: by the check of product_lines'
     * shape (when product_lines is an array) and by the check of the length as
     * sent (when a text field reaches it), so a body with neither is never
     * decoded. A body the object
     * decode rejects (e.g. a key starting with NUL) leaves the as-sent check
     * nothing to read, so only the trimmed value is checked.
     */
    private function sentBody(): mixed
    {
        return once(fn (): mixed => json_decode($this->getContent()));
    }
}
