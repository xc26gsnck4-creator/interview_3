<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\AssertableJsonString;
use Tests\TestCase;

final class OpenApiDocumentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->mockConsoleOutput = false;
    }

    public function test_committed_document_is_current_and_has_no_unknown_types(): void
    {
        $generatedPath = sys_get_temp_dir().'/api-'.Str::random(8).'.json';

        try {
            $exitCode = Artisan::call('scramble:export', ['--path' => $generatedPath, '--fail-on-unknown' => true]);

            self::assertSame(0, $exitCode, Artisan::output());
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
}
