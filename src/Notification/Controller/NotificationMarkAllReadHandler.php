<?php

declare(strict_types=1);

namespace OCI\Notification\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Notification\Service\NotificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/notifications/read-all — Mark all notifications as read.
 */
final class NotificationMarkAllReadHandler implements RequestHandlerInterface
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
        $this->notificationService->markAllRead($userId);

        return ApiResponse::success([
            'unread_count' => 0,
        ]);
    }
}
