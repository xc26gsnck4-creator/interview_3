<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

final class AppServiceProviderTest extends TestCase
{
    /**
     * The guards are static switches that outlive the application, so the
     * provider is booted as testing again to put back the state a test changed.
     */
    protected function tearDown(): void
    {
        $this->bootProviderAs('testing');
        parent::tearDown();
    }

    public function test_models_are_strict_outside_production(): void
    {
        self::assertTrue(Model::preventsLazyLoading());
        self::assertTrue(Model::preventsSilentlyDiscardingAttributes());
        self::assertTrue(Model::preventsAccessingMissingAttributes());
    }

    public function test_models_are_lenient_in_production(): void
    {
        $this->bootProviderAs('production');

        self::assertFalse(Model::preventsLazyLoading());
        self::assertFalse(Model::preventsSilentlyDiscardingAttributes());
        self::assertFalse(Model::preventsAccessingMissingAttributes());
    }

    private function bootProviderAs(string $environment): void
    {
        $this->app->instance('env', $environment);
        new AppServiceProvider($this->app)->boot();
    }
}
