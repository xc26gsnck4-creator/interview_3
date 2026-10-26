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
    public function test_committed_document_is_current_and_has_no_unknown_types(): void
    {
        $generatedPath = sys_get_temp_dir().'/api-'.Str::random(8).'.json';

        try {
            $exitCode = Artisan::call('scramble:export', ['--path' => $generatedPath, '--fail-on-unknown' => true]);

            self::assertSame(0, $exitCode, Artisan::output());

            $generated = File::get($generatedPath);

            self::assertNotEmpty(new AssertableJsonString($generated)->json('paths'));
            self::assertJsonStringEqualsJsonFile(
                base_path('api.json'),
                $generated,
                'api.json is out of date: run `vendor/bin/sail composer api:export` and commit it.',
            );
        } finally {
            File::delete($generatedPath);
        }
    }
}
