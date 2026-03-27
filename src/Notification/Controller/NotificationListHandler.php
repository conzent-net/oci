<?php

declare(strict_types=1);

namespace OCI\Notification\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Notification\Service\NotificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /app/notifications — Return all notifications with read state.
 */
final class NotificationListHandler implements RequestHandlerInterface
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
        $notifications = $this->notificationService->getAll($userId);
        $unreadCount = $this->notificationService->getUnreadCount($userId);

        return ApiResponse::success([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }
}
