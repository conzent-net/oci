<?php

declare(strict_types=1);

namespace OCI\Notification\Service;

use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Monetization\Service\SubscriptionService;
use OCI\Shared\Service\EditionService;
use Psr\Log\LoggerInterface;

/**
 * Syncs user data to a SendMails.io newsletter list.
 *
 * Called after user registration and profile/company updates to keep
 * the newsletter subscriber list in sync with name, email, company, country, and tags.
 *
 * Tags are dynamic and mutually exclusive:
 *  - OCI (self-hosted): OCI
 *  - Cloud + paid subscription: CLOUD,PAID
 *  - Cloud + free (no subscription): CLOUD,FREE
 *
 * API endpoints:
 *  - GET  /subscribers/email/{email}?list_uid=  → find existing
 *  - POST /subscribers                          → create new
 *  - PATCH /subscribers/{id}                    → update fields + tags
 */
final class SendMailsService
{
    public function __construct(
        private readonly UserRepositoryInterface $userRepo,
        private readonly EditionService $edition,
        private readonly LoggerInterface $logger,
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $listId,
        private readonly ?SubscriptionService $subscriptionService = null,
    ) {}

    /**
     * Sync a user's data to the newsletter list.
     */
    public function syncSubscriber(int $userId): void
    {
        if ($this->apiKey === '' || $this->listId === '') {
            return;
        }

        $user = $this->userRepo->findById($userId);
        if ($user === null) {
            return;
        }

        $company = $this->userRepo->getUserCompany($userId);

        $email = (string) ($user['email'] ?? '');
        if ($email === '') {
            return;
        }

        $firstName = (string) ($user['first_name'] ?? '');
        $lastName = (string) ($user['last_name'] ?? '');
        $companyName = (string) ($company['company_name'] ?? '');
        $country = (string) ($company['country_code'] ?? '');
        $tags = $this->resolveTags($userId);

        $subscriberId = $this->findSubscriberByEmail($email);

        if ($subscriberId !== null) {
            $this->patchSubscriber($subscriberId, $email, $firstName, $lastName, $companyName, $country, $tags);
        } else {
            $subscriberId = $this->createSubscriber($email, $firstName, $lastName, $companyName, $country);
            if ($subscriberId !== null) {
                $this->patchSubscriber($subscriberId, $email, $firstName, $lastName, $companyName, $country, $tags);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveTags(int $userId): array
    {
        if (!$this->edition->isCloud()) {
            return ['OCI'];
        }

        $isPaid = $this->subscriptionService !== null
            && $this->subscriptionService->hasActiveAccess($userId);

        return $isPaid ? ['CLOUD', 'PAID'] : ['CLOUD', 'FREE'];
    }

    private function findSubscriberByEmail(string $email): ?int
    {
        $url = rtrim($this->apiUrl, '/') . '/subscribers/email/' . urlencode($email)
            . '?list_uid=' . urlencode($this->listId);

        $response = $this->request('GET', $url);

        return isset($response['subscribers'][0]['id']) ? (int) $response['subscribers'][0]['id'] : null;
    }

    /**
     * @return int|null The new subscriber ID, or null on failure.
     */
    private function createSubscriber(string $email, string $firstName, string $lastName, string $company, string $country): ?int
    {
        $url = rtrim($this->apiUrl, '/') . '/subscribers';

        $response = $this->request('POST', $url, [
            'list_uid' => $this->listId,
            'EMAIL' => $email,
            'FIRST_NAME' => $firstName,
            'LAST_NAME' => $lastName,
            'COMPANY' => $company,
            'COUNRTY' => $country,
        ]);

        if ($response !== null && ($response['status'] ?? 0) === 1) {
            $this->logger->info('Newsletter subscriber created', ['email' => $email]);
            return isset($response['subscriber_id']) ? (int) $response['subscriber_id'] : null;
        }

        $this->logger->warning('Newsletter subscriber creation failed', ['email' => $email, 'response' => $response]);
        return null;
    }

    /**
     * PATCH updates fields + tags in one call.
     * The `tag` field is a comma-separated string that replaces all existing tags.
     *
     * @param list<string> $tags
     */
    private function patchSubscriber(int $subscriberId, string $email, string $firstName, string $lastName, string $company, string $country, array $tags): void
    {
        $url = rtrim($this->apiUrl, '/') . '/subscribers/' . $subscriberId;

        $response = $this->request('PATCH', $url, [
            'list_uid' => $this->listId,
            'EMAIL' => $email,
            'FIRST_NAME' => $firstName,
            'LAST_NAME' => $lastName,
            'COMPANY' => $company,
            'COUNRTY' => $country,
            'tag' => implode(',', $tags),
        ]);

        if ($response !== null && ($response['status'] ?? 0) === 1) {
            $this->logger->info('Newsletter subscriber updated', ['email' => $email, 'tags' => $tags]);
        } else {
            $this->logger->warning('Newsletter subscriber update failed', [
                'subscriber_id' => $subscriberId,
                'response' => $response,
            ]);
        }
    }

    /**
     * @param array<string, string> $payload
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $url, array $payload = []): ?array
    {
        $ch = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ];

        if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_POSTFIELDS] = http_build_query($payload);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            $this->logger->warning('SendMails API request failed', ['url' => $url, 'method' => $method]);
            return null;
        }

        $decoded = json_decode((string) $response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->warning('SendMails API error', [
                'http_code' => $httpCode,
                'response' => $decoded,
                'method' => $method,
                'url' => $url,
            ]);
        }

        return \is_array($decoded) ? $decoded : null;
    }
}
