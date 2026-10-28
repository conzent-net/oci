<?php

declare(strict_types=1);

namespace OCI\Notification\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Notification\Service\NotificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/notifications/{slug} — Return a single notification with full markdown body.
 */
final class NotificationDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $slug = (string) ($request->getAttribute('slug') ?? '');

        if ($slug === '') {
            return ApiResponse::error('Missing notification slug', 400);
        }

        $notification = $this->notificationService->getOne($userId, $slug);
        if ($notification === null) {
            return ApiResponse::error('Notification not found', 404);
        }

        return ApiResponse::success($notification);
    }
}
