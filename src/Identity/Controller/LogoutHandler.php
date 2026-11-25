<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /logout — Destroy session and redirect to login.
 */
final class LogoutHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AuthService $auth,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->auth->logout();

        return ApiResponse::redirect('/login');
    }
}
