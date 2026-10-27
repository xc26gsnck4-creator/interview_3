<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\ValueObjects;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\DeviatesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use Modules\Invoices\Domain\Exceptions\InvalidProductLine;

/**
 * A line quantity or unit price: an integer from 1 to 2^31 - 1, so that
 * quantity * unit_price always fits in PHP's int.
 */
final readonly class Amount implements Castable
{
    public const int MAX = 2_147_483_647;

    private function __construct(public int $value) {}

    /**
     * Read as given: a fraction or a numeric string is not an amount, so
     * neither is coerced.
     */
    public static function tryFrom(mixed $value): ?self
    {
        return is_int($value) && $value >= 1 && $value <= self::MAX ? new self($value) : null;
    }

    /**
     * The caster checks a value at assignment, so an invalid one is rejected
     * instead of cast; increment() and decrement() only produce the new value,
     * which goes through the same check.
     *
     * @param  array<string>  $arguments
     * @return CastsAttributes<Amount, Amount|int>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes, DeviatesCastableAttributes
        {
            /**
             * @param  array<string, mixed>  $attributes
             */
            public function get(Model $model, string $key, mixed $value, array $attributes): Amount
            {
                return $this->amount($key, $value);
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): int
            {
                return ($value instanceof Amount ? $value : $this->amount($key, $value))->value;
            }

            /**
             * Eloquent runs the step through set() before the query as well, so
             * a step is itself an amount.
             *
             * @param  array<array-key, mixed>  $attributes
             */
            public function increment(mixed $model, string $key, mixed $value, array $attributes): int
            {
                return $this->amount($key, $attributes[$key] ?? null)->value + $this->amount($key, $value)->value;
            }

            /**
             * @param  array<array-key, mixed>  $attributes
             */
            public function decrement(mixed $model, string $key, mixed $value, array $attributes): int
            {
                return $this->amount($key, $attributes[$key] ?? null)->value - $this->amount($key, $value)->value;
            }

            private function amount(string $key, mixed $value): Amount
            {
                return Amount::tryFrom($value) ?? throw InvalidProductLine::invalidAmount($key, $value);
            }
        };
    }
}
