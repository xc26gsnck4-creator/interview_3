<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\MediaType;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\Type;
use Dedoc\Scramble\Support\RouteInfo;
use SplObjectStorage;

/**
 * Closes every object with properties in the operation's responses, and in
 * the shared schemas and responses they reference, with
 * `additionalProperties: false`. Spectator has no strict mode and Scramble
 * never closes an object, so without this a response field that api.json does
 * not describe passes every contract check. An object that already has
 * `additionalProperties`, such as the 422 `errors` map, keeps it. Requests
 * stay open: Laravel ignores unknown request keys. Tests run with
 * APP_DEBUG=false (phpunit.xml), so error bodies have production's shape and
 * fit the closed schemas.
 *
 * It must run after DatabaseLockedResponseExtension and the other extensions,
 * so config/scramble.php registers it last: it also closes the responses they
 * add. Scramble is a dev dependency, so this class lives in autoload-dev.
 */
final class ClosedResponseObjectsExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        /** @var SplObjectStorage<object, null> $visited */
        $visited = new SplObjectStorage;

        foreach ($operation->responses ?? [] as $response) {
            $this->closeResponse($response, $visited);
        }
    }

    /**
     * @param  SplObjectStorage<object, null>  $visited
     */
    private function closeResponse(mixed $response, SplObjectStorage $visited): void
    {
        if ($response instanceof Reference) {
            $response = $response->resolve();
        }

        if (! $response instanceof Response) {
            return;
        }

        foreach ($response->content as $content) {
            $this->closeContent($content, $visited);
        }
    }

    /**
     * @param  SplObjectStorage<object, null>  $visited
     */
    private function closeContent(mixed $content, SplObjectStorage $visited): void
    {
        if ($content instanceof MediaType) {
            $content = $content->schema;
        }

        if ($content instanceof Schema) {
            $this->closeType($content->type, $visited);
        } elseif ($content instanceof Reference) {
            $this->closeType($content, $visited);
        }
    }

    /**
     * @param  SplObjectStorage<object, null>  $visited
     */
    private function closeType(mixed $type, SplObjectStorage $visited): void
    {
        if ($type instanceof Reference) {
            $type = $type->resolve();
        }

        if ($type instanceof Schema) {
            $type = $type->type;
        }

        if (! is_object($type) || $visited->offsetExists($type)) {
            return;
        }

        $visited->offsetSet($type);

        if ($type instanceof ArrayType) {
            $this->closeType($type->items, $visited);

            return;
        }

        if (! $type instanceof ObjectType) {
            return;
        }

        if ($type->properties !== [] && ! $type->additionalProperties instanceof Type) {
            $type->additionalProperties(new NoAdditionalProperties);
        }

        foreach ($type->properties as $property) {
            $this->closeType($property, $visited);
        }
    }
}
