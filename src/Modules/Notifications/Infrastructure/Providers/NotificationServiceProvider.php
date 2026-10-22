<?php

declare(strict_types=1);

namespace Modules\Notifications\Infrastructure\Providers;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\Api\NotificationFacadeInterface;
use Modules\Notifications\Application\Facades\NotificationFacade;
use Modules\Notifications\Infrastructure\Drivers\DriverInterface;
use Modules\Notifications\Infrastructure\Drivers\DummyDriver;

final class NotificationServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->scoped(NotificationFacadeInterface::class, NotificationFacade::class);
        $this->app->scoped(DriverInterface::class, DummyDriver::class);
    }

    /** @return array<class-string> */
    public function provides(): array
    {
        return [
            NotificationFacadeInterface::class,
            DriverInterface::class,
        ];
    }
}
