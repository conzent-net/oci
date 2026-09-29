<?php

declare(strict_types=1);

namespace OCI\Consent\Controller;

use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /consents/{id} — Single consent proof view with per-category breakdown.
 */
final class ConsentDetailHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ConsentRepositoryInterface $consentRepo,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $consentId = (int) ($request->getAttribute('id') ?? 0);
        if ($consentId <= 0) {
            return ApiResponse::error('Invalid consent ID', 404);
        }

        $consent = $this->consentRepo->getConsentWithCategories($consentId);
        if ($consent === null) {
            return ApiResponse::error('Consent record not found', 404);
        }

        // Format the short consent ID (first 8 chars of consent_session, uppercase, formatted as XXXX-XXXX)
        $session = $consent['consent_session'] ?? '';
        $shortId = strtoupper(substr($session, 0, 4)) . '-' . strtoupper(substr($session, 4, 4));

        return ApiResponse::html($this->twig->render('pages/consent/detail.html.twig', [
            'title' => 'Consent Proof',
            'user' => $user,
            'consent' => $consent,
            'shortId' => $shortId,
        ]));
    }
}
