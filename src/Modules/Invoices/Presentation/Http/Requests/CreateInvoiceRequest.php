<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Requests;

use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Invoices\Application\Data\CreateInvoiceData;
use Modules\Invoices\Application\Data\ProductLineData;
use Modules\Invoices\Domain\Models\InvoiceProductLine;
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
     * line).
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $amount = ['required', 'integer:strict', 'min:1', 'max:'.InvoiceProductLine::MAX_VALUE];

        return [
            'customer_name' => ['required', 'string', 'min:1', 'max:'.self::MAX_TEXT_LENGTH],
            'customer_email' => ['required', 'email', 'max:'.self::MAX_TEXT_LENGTH],
            'product_lines' => ['array', 'list', 'max:'.self::MAX_PRODUCT_LINES],
            'product_lines.*.name' => ['required', 'string', 'min:1', 'max:'.self::MAX_TEXT_LENGTH],
            'product_lines.*.quantity' => $amount,
            'product_lines.*.unit_price' => $amount,
        ];
    }

    /**
     * The shape of product_lines is checked first, on its own. Laravel expands
     * the per-line rules over every submitted line before any rule runs, so
     * its own rules (a list of at most MAX_PRODUCT_LINES) run before the lines
     * are validated. JSON is decoded into PHP arrays, where `{}` and
     * `{"0": ...}` pass as lists, so when the array rules accepted
     * product_lines the raw body is decoded once more, keeping objects, and
     * must show a JSON array there. The check fails closed: a body that
     * decodes only into arrays (a key starting with NUL is no valid property
     * name) reports the `list` error too.
     */
    #[Override]
    protected function prepareForValidation(): void
    {
        $validator = validator($this->validationData(), ['product_lines' => $this->rules()['product_lines']]);
        $validator->after(function (Validator $validator): void {
            if (! is_array($this->validationData()['product_lines'] ?? null)) {
                return;
            }

            $body = json_decode($this->getContent());

            if (! is_object($body) || ! is_array(get_object_vars($body)['product_lines'])) {
                $validator->addFailure('product_lines', 'list');
            }
        });
        $validator->validate();
    }

    /**
     * Laravel trims a string (whitespace, control and invisible characters such
     * as a tab or U+00AD, see `Str::trim`) before the rules run, so `max` sees
     * the trimmed value while the API document's `maxLength` applies to the
     * input as sent. The raw body is therefore measured too, in characters
     * (`mb_strlen`, like JSON Schema) and only for a field with no error yet,
     * so a value over the limit after trimming reports one message. The failure
     * is the rule's own: the studly name 'Max' is what makes Laravel pick the
     * string variant of the message. It is added here, on the validator Laravel
     * builds, rather than in an `after()` method, which the final class would
     * have to keep protected for the framework to call it.
     */
    #[Override]
    protected function getValidatorInstance(): ValidatorContract
    {
        $validator = parent::getValidatorInstance();
        $validator->after(function (Validator $validator): void {
            if (! $this->isJson()) {
                return;
            }

            $body = json_decode($this->getContent(), true);
            $values = [
                'customer_name' => data_get($body, 'customer_name'),
                'customer_email' => data_get($body, 'customer_email'),
            ];
            $lines = data_get($body, 'product_lines');

            foreach (is_array($lines) ? $lines : [] as $index => $line) {
                $values["product_lines.{$index}.name"] = data_get($line, 'name');
            }

            foreach ($values as $key => $value) {
                if (is_string($value) && mb_strlen($value) > self::MAX_TEXT_LENGTH && ! $validator->errors()->has($key)) {
                    $validator->addFailure($key, 'Max', [self::MAX_TEXT_LENGTH]);
                }
            }
        });

        return $validator;
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
