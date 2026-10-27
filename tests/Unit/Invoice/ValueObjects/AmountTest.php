<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\ValueObjects;

use InvalidArgumentException;
use Iterator;
use Modules\Invoices\Domain\ValueObjects\Amount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit: the value object needs no application and no database.
 */
final class AmountTest extends TestCase
{
    /**
     * @return Iterator<string, array{int}>
     */
    public static function bounds(): Iterator
    {
        yield 'one' => [1];
        yield 'max' => [2_147_483_647];
    }

    #[DataProvider('bounds')]
    public function test_accepts_an_integer_within_the_bounds(int $value): void
    {
        self::assertSame($value, Amount::fromInt($value)->value);
    }

    /**
     * @return Iterator<string, array{int}>
     */
    public static function outOfRange(): Iterator
    {
        yield 'zero' => [0];
        yield 'above max' => [2_147_483_648];
    }

    #[DataProvider('outOfRange')]
    public function test_rejects_an_integer_out_of_range(int $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('An amount must be an integer between 1 and 2147483647, %d given.', $value));

        Amount::fromInt($value);
    }

    /**
     * @return Iterator<string, array{mixed}>
     */
    public static function notIntegersInRange(): Iterator
    {
        yield 'fraction' => [1.5];
        yield 'numeric string' => ['2'];
        yield 'null' => [null];
        yield 'zero' => [0];
    }

    #[DataProvider('notIntegersInRange')]
    public function test_try_from_is_null_for_a_value_that_is_not_an_integer_in_range(mixed $value): void
    {
        self::assertNull(Amount::tryFrom($value));
    }

    public function test_try_from_builds_an_amount_from_an_integer_in_range(): void
    {
        self::assertSame(1, Amount::tryFrom(1)?->value);
    }
}
