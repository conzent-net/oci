<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /forgot-password — Show the forgot password form.
 */
final class ForgotPasswordPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CsrfService $csrf,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $html = $this->twig->render('pages/auth/forgot-password.html.twig', [
            'title' => 'Forgot Password',
            'csrf_token' => $this->csrf->generate('forgot_password'),
            'error' => null,
            'success' => false,
        ]);

        return ApiResponse::html($html);
    }
}
