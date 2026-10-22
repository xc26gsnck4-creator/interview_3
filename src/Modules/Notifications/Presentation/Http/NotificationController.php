<?php

declare(strict_types=1);

namespace Modules\Notifications\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Modules\Notifications\Application\Services\NotificationService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class NotificationController
{
    public function __construct(
        private NotificationService $notificationService,
    ) {}

    public function hook(string $action, string $reference): JsonResponse
    {
        match ($action) {
            'delivered' => $this->notificationService->delivered(
                reference: Uuid::fromString($reference),
            ),
            default => throw new NotFoundHttpException("Unknown notification action: {$action}"),
        };

        return new JsonResponse(data: null, status: Response::HTTP_NO_CONTENT);
    }
}
