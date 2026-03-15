<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /account/setup — Onboarding form for new users (profile + company).
 * POST /account/setup — Save profile + company, redirect to /sites.
 */
final class AccountSetupHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly TwigEnvironment $twig,
        private readonly CsrfService $csrf,
        private readonly UserRepositoryInterface $userRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];

        // If company already exists, skip setup
        $company = $this->userRepo->getUserCompany($userId);
        if ($company !== null) {
            return ApiResponse::redirect('/');
        }

        if ($request->getMethod() === 'POST') {
            return $this->handlePost($request, $user);
        }

        return $this->renderForm($user);
    }

    private function renderForm(array $user, ?string $error = null, array $old = []): ResponseInterface
    {
        $html = $this->twig->render('pages/account/setup.html.twig', [
            'csrf_token' => $this->csrf->generate('account_setup'),
            'error' => $error,
            'user' => $user,
            'old' => $old,
        ]);

        return ApiResponse::html($html);
    }

    private function handlePost(ServerRequestInterface $request, array $user): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $userId = (int) $user['id'];

        // CSRF check
        $token = (string) ($body['_csrf_token'] ?? '');
        if (!$this->csrf->validate($token, 'account_setup')) {
            return $this->renderForm($user, 'Invalid security token. Please try again.', $body);
        }

        // Extract and trim fields
        $firstName = trim((string) ($body['first_name'] ?? ''));
        $lastName = trim((string) ($body['last_name'] ?? ''));
        $companyName = trim((string) ($body['company_name'] ?? ''));
        $address = trim((string) ($body['address'] ?? ''));
        $city = trim((string) ($body['city'] ?? ''));
        $zip = trim((string) ($body['zip'] ?? ''));
        $state = trim((string) ($body['state'] ?? ''));
        $countryCode = trim((string) ($body['country_code'] ?? ''));
        $phone = trim((string) ($body['phone'] ?? ''));
        $vatNumber = trim((string) ($body['vat_number'] ?? ''));

        // Validate required fields
        $errors = [];
        if ($firstName === '') {
            $errors[] = 'First name is required.';
        }
        if ($lastName === '') {
            $errors[] = 'Last name is required.';
        }
        if ($companyName === '') {
            $errors[] = 'Company name is required.';
        }

        if ($errors !== []) {
            return $this->renderForm($user, implode(' ', $errors), $body);
        }

        // Update user profile
        $this->userRepo->update($userId, [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        // Create company record
        $this->userRepo->upsertUserCompany($userId, [
            'company_name' => $companyName,
            'address' => $address,
            'city' => $city,
            'zip' => $zip,
            'state' => $state,
            'country_code' => $countryCode,
            'phone' => $phone,
            'vat_number' => $vatNumber,
        ]);

        return ApiResponse::redirect('/sites');
    }
}
