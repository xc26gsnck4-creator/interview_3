<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Dedoc\Scramble\Scramble;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks a test's requests and responses against the committed OpenAPI
 * document. The test class calls useCommittedOpenApiDocument() in setUp(),
 * before its first request, because Spectator validates while the request runs.
 */
trait ValidatesOpenApiContract
{
    /**
     * Scramble moves a single route prefix (`api`) out of the documented paths
     * into `servers`, so Spectator gets the same prefix back.
     */
    protected function useCommittedOpenApiDocument(): void
    {
        config()->set('spectator.sources.local.base_path', base_path());

        Spectator::using('api.json')
            ->setPathPrefix(Scramble::getGeneratorConfig(Scramble::DEFAULT_API)->apiPath()->serverPath());
    }

    /**
     * The one place that calls Spectator's TestResponse macros (see phpstan.neon).
     *
     * @param  TestResponse<Response>  $response
     */
    protected function assertMatchesContract(TestResponse $response, int $status, bool $validRequest = true): void
    {
        if ($validRequest) {
            $response->assertValidRequest();
        }

        $response->assertValidResponse($status);
    }
}
