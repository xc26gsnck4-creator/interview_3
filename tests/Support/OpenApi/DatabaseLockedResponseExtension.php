<?php

declare(strict_types=1);

namespace Tests\Support\OpenApi;

use Dedoc\Scramble\Extensions\OperationExtension;
use Dedoc\Scramble\Support\Generator\Operation;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\RouteInfo;
use Illuminate\Http\Response as HttpResponse;

/**
 * Documents the 503 App\Exceptions\DatabaseLockedRenderer answers on every api
 * route when the database stays locked past the busy timeout: bootstrap/app.php
 * registers it for the whole application, so Scramble, which documents what a
 * route's own code throws, cannot see it. An operation that already documents
 * a 503 (send, for NotificationFailed) keeps that one, with the same
 * `{"message": ...}` body Scramble documents for an HTTP exception. Scramble
 * is a dev dependency, so this class lives in autoload-dev and
 * config/scramble.php registers it.
 */
final class DatabaseLockedResponseExtension extends OperationExtension
{
    public function handle(Operation $operation, RouteInfo $routeInfo): void
    {
        foreach ($operation->responses ?? [] as $response) {
            if ($response instanceof Response && $response->code === HttpResponse::HTTP_SERVICE_UNAVAILABLE) {
                return;
            }
        }

        $body = new ObjectType;
        $body->addProperty('message', (new StringType)->setDescription('Error overview.'));
        $body->setRequired(['message']);

        $schema = new Schema;
        $schema->type = $body;

        $operation->addResponse(
            Response::make(HttpResponse::HTTP_SERVICE_UNAVAILABLE)
                ->setDescription(HttpResponse::$statusTexts[HttpResponse::HTTP_SERVICE_UNAVAILABLE])
                ->setContent('application/json', $schema),
        );
    }
}
