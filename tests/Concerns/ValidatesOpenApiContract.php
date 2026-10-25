<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Dedoc\Scramble\Scramble;
use Illuminate\Routing\Route;
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
     * into `servers`, so Spectator gets the same prefix back. The local source
     * is pinned, so SPEC_SOURCE cannot point the checks at another document.
     */
    protected function useCommittedOpenApiDocument(): void
    {
        config()->set('spectator.default', 'local');
        config()->set('spectator.sources.local.base_path', base_path());

        Spectator::using('api.json')
            ->setPathPrefix(Scramble::getGeneratorConfig(Scramble::DEFAULT_API)->apiPath()->serverPath());
    }

    /**
     * The one place that calls Spectator's TestResponse macros (see phpstan.neon).
     * Spectator's middleware runs only on routes of the `api` group and leaves
     * nothing behind otherwise, so the macros alone would pass for a request
     * that was never checked. A test that sends an invalid request on purpose
     * passes $validRequest false, so only the response is checked.
     *
     * @param  TestResponse<Response>  $response
     */
    protected function assertMatchesContract(TestResponse $response, int $status, bool $validRequest = true): void
    {
        $route = $response->baseRequest?->route();

        self::assertInstanceOf(Route::class, $route, 'The request matched no route, so Spectator checked nothing.');
        self::assertContains('api', $route->gatherMiddleware(), 'Spectator checks only routes in the api middleware group.');

        if ($validRequest) {
            $response->assertValidRequest();
        }

        $response->assertValidResponse($status);
    }
}
