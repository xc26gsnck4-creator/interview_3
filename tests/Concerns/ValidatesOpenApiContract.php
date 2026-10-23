<?php

declare(strict_types=1);

namespace Tests\Concerns;

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
     * Scramble moves the single `api` route prefix into `servers`, so the paths
     * in the document start after it and Spectator gets it back as a prefix.
     */
    protected function useCommittedOpenApiDocument(): void
    {
        config()->set('spectator.sources.local.base_path', base_path());

        Spectator::using('api.json')->setPathPrefix('api');
    }

    /**
     * The one place that calls Spectator's TestResponse macros: Larastan types
     * them as static methods returning mixed (see phpstan.neon).
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
