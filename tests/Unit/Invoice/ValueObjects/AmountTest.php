<?php

declare(strict_types=1);

namespace Tests\Unit\Invoice\ValueObjects;

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
    public function test_builds_an_amount_from_an_integer_within_the_bounds(int $value): void
    {
        self::assertSame($value, Amount::tryFrom($value)?->value);
    }

    /**
     * @return Iterator<string, array{mixed}>
     */
    public static function notIntegersInRange(): Iterator
    {
        yield 'zero' => [0];
        yield 'above max' => [2_147_483_648];
        yield 'fraction' => [1.5];
        yield 'numeric string' => ['2'];
        yield 'null' => [null];
    }

    #[DataProvider('notIntegersInRange')]
    public function test_is_null_for_a_value_that_is_not_an_integer_in_range(mixed $value): void
    {
        self::assertNull(Amount::tryFrom($value));
    }
}
