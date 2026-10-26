<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Arr;

/**
 * Reads a JSON pointer (RFC 6901) against a decoded JSON document.
 */
trait ResolvesJsonPointers
{
    /**
     * Resolves a local reference (`#/a/b`) segment by segment, so a component
     * name with dots resolves too. Null for a reference that is not local or
     * does not resolve to an object or an array.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>|null
     */
    protected function resolvePointer(array $document, string $reference): ?array
    {
        if (! str_starts_with($reference, '#/')) {
            return null;
        }

        $target = $document;

        foreach ($this->pointerTokens(substr($reference, 1)) as $token) {
            if (! is_array($target) || ! Arr::exists($target, $token)) {
                return null;
            }

            $target = $target[$token];
        }

        return is_array($target) ? $target : null;
    }

    /**
     * The tokens of a JSON pointer (`~1` is `/`, `~0` is `~`).
     *
     * @return list<string>
     */
    protected function pointerTokens(string $pointer): array
    {
        return array_map(
            static fn (string $token): string => str_replace(['~1', '~0'], ['/', '~'], $token),
            array_slice(explode('/', $pointer), 1),
        );
    }
}
