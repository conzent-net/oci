<?php

declare(strict_types=1);

namespace OCI\Notification\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Notification\Service\NotificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/notifications/read — Mark a single notification as read.
 */
final class NotificationMarkReadHandler implements RequestHandlerInterface
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
        $body = (array) ($request->getParsedBody() ?? []);
        $slug = (string) ($body['slug'] ?? '');

        if ($slug === '') {
            return ApiResponse::error('Missing notification slug', 400);
        }

        $this->notificationService->markRead($userId, $slug);

        return ApiResponse::success([
            'unread_count' => $this->notificationService->getUnreadCount($userId),
        ]);
    }
}
