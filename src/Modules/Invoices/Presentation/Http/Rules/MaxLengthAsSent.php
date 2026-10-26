<?php

declare(strict_types=1);

namespace Modules\Invoices\Presentation\Http\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Laravel trims a string (whitespace, control and invisible characters such as
 * a tab or U+00AD, see `Str::trim`) before the rules run, so `max` sees the
 * trimmed value while the API document's `maxLength` applies to the input as
 * sent. This rule measures the field in the body as sent, in characters
 * (`mb_strlen`, like JSON Schema), and fails with Laravel's own `max` message
 * for a string.
 */
final readonly class MaxLengthAsSent implements ValidationRule
{
    /**
     * @param  array<array-key, mixed>  $sentBody  the JSON body as sent, decoded once for every field
     */
    public function __construct(
        private int $maxLength,
        private array $sentBody,
    ) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sent = data_get($this->sentBody, $attribute);

        if (is_string($sent) && mb_strlen($sent) > $this->maxLength) {
            $fail('validation.max.string')->translate(['max' => $this->maxLength]);
        }
    }
}
