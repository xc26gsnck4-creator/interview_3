<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AppServiceProviderTest extends TestCase
{
    /**
     * The guards are static switches that outlive the application, so a test
     * that boots the provider as production puts back the testing state.
     */
    protected function tearDown(): void
    {
        Model::shouldBeStrict();
        DB::prohibitDestructiveCommands(false);
        parent::tearDown();
    }

    public function test_models_are_strict_outside_production(): void
    {
        self::assertTrue(Model::preventsLazyLoading());
        self::assertTrue(Model::preventsSilentlyDiscardingAttributes());
        self::assertTrue(Model::preventsAccessingMissingAttributes());
    }

    /**
     * Pretend keeps the rollback harmless; db:wipe and migrate:fresh cannot run
     * inside RefreshDatabase's transaction on in-memory SQLite.
     */
    public function test_destructive_commands_are_allowed_outside_production(): void
    {
        $this->mockArtisan('migrate:rollback', ['--pretend' => true])->assertSuccessful();
    }

    public function test_models_are_lenient_in_production(): void
    {
        $this->bootAsProduction();

        self::assertFalse(Model::preventsLazyLoading());
        self::assertFalse(Model::preventsSilentlyDiscardingAttributes());
        self::assertFalse(Model::preventsAccessingMissingAttributes());
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function destructiveCommands(): Iterator
    {
        yield 'db:wipe' => ['db:wipe'];
        yield 'migrate:fresh' => ['migrate:fresh'];
        yield 'migrate:refresh' => ['migrate:refresh'];
        yield 'migrate:reset' => ['migrate:reset'];
        yield 'migrate:rollback' => ['migrate:rollback'];
    }

    /**
     * No --force: without the guard the command would stop at the production
     * confirmation prompt instead of running.
     */
    #[DataProvider('destructiveCommands')]
    public function test_destructive_commands_are_prohibited_in_production(string $command): void
    {
        $this->bootAsProduction();

        $this->mockArtisan($command)
            ->expectsOutputToContain('This command is prohibited from running in this environment.')
            ->assertFailed();
    }

    private function bootAsProduction(): void
    {
        $this->app->instance('env', 'production');
        new AppServiceProvider($this->app)->boot();
    }
}
