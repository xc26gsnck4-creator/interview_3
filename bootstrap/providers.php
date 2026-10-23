<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Modules\Notifications\Infrastructure\Providers\NotificationServiceProvider;

return [
    AppServiceProvider::class,
    NotificationServiceProvider::class,
];
