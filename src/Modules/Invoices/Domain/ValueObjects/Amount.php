<?php

declare(strict_types=1);

namespace Modules\Invoices\Domain\ValueObjects;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\DeviatesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Modules\Invoices\Domain\Exceptions\InvalidProductLine;
use UnexpectedValueException;

/**
 * A line quantity or unit price: an integer from 1 to 2^31 - 1, so that
 * quantity * unit_price always fits in PHP's int.
 */
final readonly class Amount implements Castable
{
    public const int MAX = 2_147_483_647;

    private function __construct(public int $value) {}

    public static function fromInt(int $value): self
    {
        return self::tryFrom($value) ?? throw new InvalidArgumentException(
            sprintf('An amount must be an integer between 1 and %d, %d given.', self::MAX, $value),
        );
    }

    /**
     * Null for anything that is not an integer in range, read as given: a
     * fraction or a numeric string is not an amount.
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
                return $this->stored($key, $value);
            }

            /**
             * @param  array<string, mixed>  $attributes
             */
            public function set(Model $model, string $key, mixed $value, array $attributes): int
            {
                $amount = $value instanceof Amount ? $value : Amount::tryFrom($value);

                if ($amount === null) {
                    throw InvalidProductLine::invalidAmount($key, $value);
                }

                return $amount->value;
            }

            /**
             * @param  array<array-key, mixed>  $attributes
             */
            public function increment(mixed $model, string $key, mixed $value, array $attributes): int
            {
                return $this->stored($key, $attributes[$key] ?? null)->value + $this->step($value);
            }

            /**
             * @param  array<array-key, mixed>  $attributes
             */
            public function decrement(mixed $model, string $key, mixed $value, array $attributes): int
            {
                return $this->stored($key, $attributes[$key] ?? null)->value - $this->step($value);
            }

            private function stored(string $key, mixed $value): Amount
            {
                return Amount::tryFrom($value) ?? throw new UnexpectedValueException(
                    sprintf('Stored %s is not an amount: %s.', $key, var_export($value, true)),
                );
            }

            private function step(mixed $value): int
            {
                return is_int($value) ? $value : throw new InvalidArgumentException(
                    sprintf('An amount changes by an integer, %s given.', var_export($value, true)),
                );
            }
        };
    }
}
