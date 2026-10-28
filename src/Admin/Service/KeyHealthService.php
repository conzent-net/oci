<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use OCI\Identity\Service\MailerService;
use OCI\Infrastructure\Alert\GapWebhook;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Checks that every external key the platform depends on is still accepted,
 * and puts a dead one on the operator's gap list.
 *
 * Each check is one free, read-only call that proves the key authenticates.
 * A key the provider rejects alerts at once. A provider that cannot be reached
 * alerts only after UNREACHABLE_LIMIT checks in a row, so a network blip does
 * not page anyone. A check that passes resolves its event. A key that is not
 * configured is skipped: self-hosted installs leave most of them empty.
 */
final class KeyHealthService
{
    private const UNREACHABLE_LIMIT = 3;
    private const STREAK_PREFIX = 'keyhealth:unreachable:';

    /** @var callable(string, string, list<string>, ?string, ?string): array{0: int, 1: string} */
    private $http;

    /** @var callable(): string */
    private $mailCheck;

    public function __construct(
        private readonly GapWebhook $gaps,
        private readonly LoggerInterface $logger,
        private readonly ?RedisClient $redis = null,
        ?MailerService $mailer = null,
        /** Test seams: MailerService is final and the checks call real providers. */
        ?callable $http = null,
        ?callable $mailCheck = null,
    ) {
        $this->http = $http ?? self::request(...);
        $this->mailCheck = $mailCheck ?? ($mailer !== null ? $mailer->verifyLogin(...) : static fn (): string => 'skipped');
    }

    /**
     * Run every check and report each result to the gap list.
     *
     * @return array<string, string> check name => ok|rejected|unreachable|skipped
     */
    public function checkAll(): array
    {
        $results = [];
        foreach ($this->checks() as $name => [$severity, $title, $check]) {
            try {
                $result = $check();
            } catch (\Throwable $e) {
                $result = 'unreachable';
            }
            $results[$name] = $result;
            $this->report($name, $result, $severity, $title);
        }

        return $results;
    }

    /** @return array<string, array{0: int, 1: string, 2: callable(): string}> */
    private function checks(): array
    {
        return [
            'cloudflare' => [2, 'Cloudflare API token rejected', $this->cloudflare(...)],
            'stripe' => [1, 'Stripe secret key rejected', $this->stripe(...)],
            'endpointr' => [2, 'Endpointr API key rejected', $this->endpointr(...)],
            'mail' => [2, 'Mail server login rejected', fn (): string => ($this->mailCheck)()],
            'openrouter' => [3, 'OpenRouter API key rejected', $this->openRouter(...)],
            'turnstile' => [2, 'Turnstile secret key rejected', $this->turnstile(...)],
            'dataforseo' => [3, 'DataForSEO login rejected', $this->dataForSeo(...)],
        ];
    }

    private function report(string $name, string $result, int $severity, string $title): void
    {
        $key = 'key-' . $name;

        if ($result === 'ok') {
            $this->streak($name, reset: true);
            $this->gaps->resolve($key);

            return;
        }

        if ($result === 'rejected') {
            $this->logger->error('Key health: ' . $title);
            $this->gaps->open($key, $severity, $title, 'The provider refused the key in the hourly key check. Replace it in Coolify and restart.');

            return;
        }

        if ($result === 'unreachable' && $this->streak($name) >= self::UNREACHABLE_LIMIT) {
            $service = ucfirst($name);
            $this->logger->warning("Key health: {$service} unreachable for " . self::UNREACHABLE_LIMIT . ' checks');
            $this->gaps->open($key, $severity, "{$service} unreachable from the app", 'The hourly key check could not reach the provider three times in a row.');
        }
    }

    /** Count consecutive unreachable checks; without Redis it never reaches the limit. */
    private function streak(string $name, bool $reset = false): int
    {
        if ($this->redis === null) {
            return 0;
        }

        try {
            if ($reset) {
                $this->redis->del([self::STREAK_PREFIX . $name]);

                return 0;
            }
            $count = (int) $this->redis->incr(self::STREAK_PREFIX . $name);
            $this->redis->expire(self::STREAK_PREFIX . $name, 2 * 86400);

            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function cloudflare(): string
    {
        $token = self::env('CLOUDFLARE_API_TOKEN');
        if ($token === '') {
            return 'skipped';
        }

        [$status, $body] = ($this->http)('GET', 'https://api.cloudflare.com/client/v4/user/tokens/verify', ['Authorization: Bearer ' . $token], null, null);
        if ($status === 200) {
            $json = json_decode($body, true);

            return (($json['result']['status'] ?? '') === 'active') ? 'ok' : 'rejected';
        }

        return self::byStatus($status);
    }

    private function stripe(): string
    {
        $mode = strtolower(self::env('STRIPE_MODE') ?: 'test');
        $key = self::env($mode === 'live' ? 'STRIPE_LIVE_SECRET_KEY' : 'STRIPE_TEST_SECRET_KEY');
        if ($key === '') {
            return 'skipped';
        }

        [$status] = ($this->http)('GET', 'https://api.stripe.com/v1/balance', [], null, $key . ':');

        // 403 is a restricted key without balance access: the key itself works.
        return $status === 403 ? 'ok' : self::byStatus($status);
    }

    private function endpointr(): string
    {
        $key = self::env('ENDPOINTR_API_KEY');
        if ($key === '') {
            return 'skipped';
        }

        [$status] = ($this->http)('POST', 'https://api.endpointr.com/v1/token', ['Content-Type: application/json'], (string) json_encode(['api_key' => $key]), null);

        return self::byStatus($status);
    }

    private function openRouter(): string
    {
        $key = self::env('OPENROUTER_API_KEY');
        if ($key === '') {
            return 'skipped';
        }

        [$status] = ($this->http)('GET', 'https://openrouter.ai/api/v1/key', ['Authorization: Bearer ' . $key], null, null);

        return self::byStatus($status);
    }

    private function turnstile(): string
    {
        $secret = self::env('TURNSTILE_SECRET_KEY');
        if ($secret === '') {
            return 'skipped';
        }

        // A made-up response token: a good secret answers "invalid-input-response",
        // a dead one "invalid-input-secret".
        [$status, $body] = ($this->http)('POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [], http_build_query(['secret' => $secret, 'response' => 'key-health-probe']), null);
        if ($status === 0 || $status >= 500) {
            return 'unreachable';
        }
        $codes = (array) (json_decode($body, true)['error-codes'] ?? []);

        return \in_array('invalid-input-secret', $codes, true) ? 'rejected' : 'ok';
    }

    private function dataForSeo(): string
    {
        $login = self::env('DATAFORSEO_LOGIN');
        $password = self::env('DATAFORSEO_PASSWORD');
        if ($login === '' || $password === '') {
            return 'skipped';
        }

        [$status, $body] = ($this->http)('GET', 'https://api.dataforseo.com/v3/appendix/user_data', [], null, $login . ':' . $password);
        if ($status === 200 && (int) (json_decode($body, true)['status_code'] ?? 0) === 40100) {
            return 'rejected';
        }

        return self::byStatus($status);
    }

    private static function byStatus(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'ok',
            \in_array($status, [400, 401, 403], true) => 'rejected',
            default => 'unreachable',
        };
    }

    private static function env(string $name): string
    {
        return trim((string) ($_ENV[$name] ?? getenv($name) ?: ''));
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0: int, 1: string}
     */
    private static function request(string $method, string $url, array $headers, ?string $body, ?string $userPwd): array
    {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($userPwd !== null) {
            $options[CURLOPT_USERPWD] = $userPwd;
        }
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, \is_string($response) ? $response : ''];
    }
}
