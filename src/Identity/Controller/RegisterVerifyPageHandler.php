<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use OCI\Identity\Service\EmailVerificationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /register/verify — Non-JS fallback page that renders the code-entry
 * form. Shown after a non-AJAX POST /register or when JS is disabled.
 */
final class RegisterVerifyPageHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly EmailVerificationService $verification,
        private readonly CsrfService $csrf,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $pending = $this->verification->getPendingSignupDisplay();
        if ($pending === null) {
            return ApiResponse::redirect('/register');
        }

        $error = (string) ($request->getQueryParams()['error'] ?? '');

        $html = $this->twig->render('pages/auth/verify-code.html.twig', [
            'title' => 'Verify Your Email',
            'csrf_token' => $this->csrf->generate('register_verify'),
            'email' => $pending['email'],
            'first_name' => $pending['first_name'],
            'error' => $error,
        ]);

        return ApiResponse::html($html);
    }
}
