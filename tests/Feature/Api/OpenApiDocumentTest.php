<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
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

    public function test_committed_document_matches_the_code(): void
    {
        $generatedPath = sys_get_temp_dir().'/api-'.Str::random(8).'.json';

        try {
            $this->runSuccessfully('scramble:export', ['--path' => $generatedPath]);

            self::assertFileEquals(
                base_path('api.json'),
                $generatedPath,
                'api.json is out of date: run `vendor/bin/sail composer api:export` and commit it.',
            );
        } finally {
            File::delete($generatedPath);
        }
    }

    public function test_document_has_operations_and_no_unknown_types(): void
    {
        $this->runSuccessfully('scramble:analyze', ['--fail-on-empty' => true, '--fail-on-unknown' => true]);
    }

    public function test_document_is_valid_openapi(): void
    {
        $this->runSuccessfully('spectator:validate', ['--spec' => 'api.json']);
    }

    /**
     * Scramble documents a route registered for several methods (Route::match,
     * Route::any, Route::redirect) under its first method only; the other
     * methods are served but missing from the document.
     */
    public function test_every_api_route_is_documented(): void
    {
        $output = $this->runSuccessfully('spectator:routes', ['--spec' => 'api.json', '--prefix' => 'api', '--format' => 'json']);
        $report = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($report);
        self::assertSame([], $report['undocumented_routes']);
        self::assertIsArray($report['spec_operations']);
        self::assertSame([], array_filter(
            $report['spec_operations'],
            static fn (mixed $operation): bool => data_get($operation, 'status') !== 'matched',
        ));
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
