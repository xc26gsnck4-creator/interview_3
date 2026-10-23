<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\AssertableJsonString;
use Spectator\Spectator;
use Tests\Concerns\ValidatesOpenApiContract;
use Tests\TestCase;

final class OpenApiDocumentTest extends TestCase
{
    use ValidatesOpenApiContract;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConsoleOutput = false;
        $this->useCommittedOpenApiDocument();
    }

    public function test_committed_document_is_current_and_has_no_unknown_types(): void
    {
        $generatedPath = sys_get_temp_dir().'/api-'.Str::random(8).'.json';

        try {
            $this->runSuccessfully('scramble:export', ['--path' => $generatedPath, '--fail-on-unknown' => true]);

            self::assertNotEmpty(new AssertableJsonString(File::get($generatedPath))->json('paths'));
            self::assertJsonFileEqualsJsonFile(
                base_path('api.json'),
                $generatedPath,
                'api.json is out of date: run `vendor/bin/sail composer api:export` and commit it.',
            );
        } finally {
            File::delete($generatedPath);
        }
    }

    /**
     * Scramble documents a route registered for several methods (Route::match,
     * Route::any, Route::redirect) under its first method only; the other
     * methods are served but missing from the document.
     */
    public function test_every_api_route_is_documented(): void
    {
        $output = $this->runSuccessfully('spectator:routes', ['--prefix' => Spectator::getPathPrefix(), '--format' => 'json']);

        new AssertableJsonString($output)
            ->assertPath('undocumented_routes', [])
            ->assertMissing(['status' => 'unimplemented']);
    }

    /**
     * @param  array<string, bool|string>  $parameters
     */
    private function runSuccessfully(string $command, array $parameters): string
    {
        $exitCode = Artisan::call($command, $parameters);
        $output = Artisan::output();

        self::assertSame(0, $exitCode, $output);

        return $output;
    }
}
