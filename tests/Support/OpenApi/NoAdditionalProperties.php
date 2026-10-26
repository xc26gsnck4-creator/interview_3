<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * `ObjectType::$additionalProperties` holds a `Type`, so the schema value
 * `false` ("no other properties") needs a type of its own that serialises as
 * the boolean.
 */
final class NoAdditionalProperties extends Type
{
    public function __construct()
    {
        parent::__construct('object');
    }

    public function serializeAs31(): bool
    {
        return false;
    }

    public function serializeAs32(): bool
    {
        return false;
    }
}
