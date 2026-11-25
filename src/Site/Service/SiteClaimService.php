<?php

declare(strict_types=1);

namespace OCI\Site\Service;

use OCI\Admin\Service\AuditLogService;
use OCI\Identity\Service\RateLimiter;
use OCI\Infrastructure\Http\ChallengeFetcherInterface;
use OCI\Infrastructure\Http\UrlGuard;
use OCI\Site\DTO\ClaimResult;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Log\LoggerInterface;

use function count;
use function in_array;
use function is_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * Hands a site's website key to a CMS plugin that proves it runs on that
 * site's domain. ACME HTTP-01 in miniature:
 *
 *  1. the plugin publishes a random token at a URL on its own site,
 *  2. it posts domain, challenge URL and token here,
 *  3. we fetch the challenge URL (guarded, pinned, no redirects) and compare.
 *
 * The website key is not a secret: it sits in the page source of every site
 * running the banner. What the challenge prevents is a public "domain to key"
 * directory that would let anyone embed a customer's banner elsewhere and
 * pollute that customer's consent logs. Nothing is ever created here; a
 * domain with no Conzent site is told so and that is the end of it.
 *
 * Order matters and is deliberate: validation first (garbage costs no
 * buckets), then the rate limits, then the challenge host check, then the
 * lookup. `no_site` and `ambiguous` are answered before the fetch: whether
 * a domain runs Conzent is visible in that site's own page source, so this
 * discloses nothing. `suspended` is answered only after the challenge
 * passes, because the reason is billing state and belongs to the owner.
 */
final class SiteClaimService
{
    public const CODE_INVALID_REQUEST = 'invalid_request';
    public const CODE_RATE_LIMITED = 'rate_limited';
    public const CODE_NO_SITE = 'no_site';
    public const CODE_AMBIGUOUS = 'ambiguous';
    public const CODE_CHALLENGE_UNREACHABLE = 'challenge_unreachable';
    public const CODE_CHALLENGE_MISMATCH = 'challenge_mismatch';
    public const CODE_SUSPENDED = 'suspended';

    /** A shared host running a MainWP burst of fifty to a hundred sites fits in one hour. */
    public const IP_LIMIT = 120;
    public const IP_WINDOW = 3600;

    /** One site claims once; a handful of retries while fixing a cache is fine. */
    public const DOMAIN_LIMIT = 10;
    public const DOMAIN_WINDOW = 600;

    private const TOKEN_PATTERN = '/^[a-f0-9]{64}$/';
    private const MAX_URL_LENGTH = 2048;

    /** @var callable(string, int, int): bool */
    private $allow;

    /**
     * @param callable|null $allow fn(string $bucket, int $limit, int $window): bool, a test seam over the rate limiter
     */
    public function __construct(
        private readonly SiteRepositoryInterface $sites,
        private readonly DomainNormaliser $domains,
        private readonly UrlGuard $guard,
        private readonly ChallengeFetcherInterface $fetcher,
        RateLimiter $rateLimiter,
        private readonly AuditLogService $audit,
        private readonly LoggerInterface $logger,
        ?callable $allow = null,
    ) {
        $this->allow = $allow ?? static fn (string $bucket, int $limit, int $window): bool
            => $rateLimiter->allow($bucket, $limit, $window);
    }

    /**
     * @param array<string, mixed> $payload the decoded JSON body
     * @param string               $clientIp REMOTE_ADDR, or '' when unknown (CLI, tests)
     */
    public function claim(array $payload, string $clientIp, ?string $userAgent = null): ClaimResult
    {
        [$input, $errors] = $this->validate($payload);
        if ($errors !== []) {
            return ClaimResult::failed(self::CODE_INVALID_REQUEST, 422, 'The claim request is incomplete.', $errors);
        }
        $host = $input['host'];

        // Counted before anything touches the database or the network.
        if ($clientIp !== '' && !($this->allow)('plugin-claim:ip:' . $clientIp, self::IP_LIMIT, self::IP_WINDOW)) {
            return $this->rateLimited(self::IP_WINDOW);
        }
        if (!($this->allow)('plugin-claim:domain:' . hash('sha256', $host), self::DOMAIN_LIMIT, self::DOMAIN_WINDOW)) {
            return $this->rateLimited(self::DOMAIN_WINDOW);
        }

        $urlError = $this->challengeUrlError($input['challenge_url'], $host);
        if ($urlError !== null) {
            return ClaimResult::failed(self::CODE_INVALID_REQUEST, 422, 'The challenge URL is not acceptable.', ['challenge_url' => $urlError]);
        }

        $candidates = $this->candidates($host);
        if ($candidates === []) {
            $this->logger->info('Plugin claim: no site for ' . $host);

            return ClaimResult::failed(
                self::CODE_NO_SITE,
                404,
                sprintf('No Conzent site matches %s. Create the site in your Conzent account, then retry.', $host),
            );
        }
        if (count($candidates) > 1) {
            $this->logger->warning('Plugin claim: ' . count($candidates) . ' sites match ' . $host);

            return ClaimResult::failed(
                self::CODE_AMBIGUOUS,
                409,
                sprintf('More than one Conzent site matches %s. Enter the website key of the right site by hand.', $host),
                [],
                ['candidates' => count($candidates)],
            );
        }
        $site = $candidates[0];

        $guarded = $this->guard->check($input['challenge_url']);
        if (!$guarded['ok']) {
            return $this->unreachable($host, $input['challenge_url'], $guarded['reason']);
        }

        $fetch = $this->fetcher->fetch(
            $this->withCacheBuster($input['challenge_url']),
            ['host' => $guarded['host'], 'port' => $guarded['port'], 'pin_ip' => $guarded['pin_ip']],
        );
        if (!$fetch['ok']) {
            return $this->unreachable($host, $input['challenge_url'], $fetch['error']);
        }
        if ($fetch['status_code'] !== 200) {
            return $this->unreachable($host, $input['challenge_url'], 'responder answered HTTP ' . $fetch['status_code']);
        }

        $actor = [
            'actor' => 'plugin',
            'domain' => $host,
            'challenge_url' => $input['challenge_url'],
            'plugin' => $input['plugin'],
            'plugin_version' => $input['plugin_version'],
        ];

        if (!$this->tokenMatches($input['token'], $fetch)) {
            // A wrong token against a real site is the one failure worth an audit row.
            $this->audit->log(
                userId: (int) $site['user_id'],
                action: 'claim_rejected',
                entityType: 'Site',
                entityId: (int) $site['id'],
                newValues: $actor + ['reason' => self::CODE_CHALLENGE_MISMATCH],
                ipAddress: $clientIp !== '' ? $clientIp : null,
                userAgent: $userAgent,
            );

            $hint = str_starts_with(ltrim($fetch['body']), '<')
                ? 'the responder returned HTML; a cache or another plugin may be answering the request'
                : 'the token did not match';

            return ClaimResult::failed(
                self::CODE_CHALLENGE_MISMATCH,
                403,
                sprintf('Conzent reached %s but the response did not carry the expected token.', $host),
                [],
                ['reason' => $hint],
            );
        }

        $status = (string) ($site['status'] ?? '');
        if ($status !== 'active') {
            $reason = (string) ($site['suspended_reason'] ?? '');
            if ($reason === '') {
                $reason = $status === '' ? 'inactive' : $status;
            }

            return ClaimResult::failed(
                self::CODE_SUSPENDED,
                409,
                sprintf('The Conzent site for %s is not active (%s). Resolve this in your Conzent account, then retry.', $host, $reason),
                [],
                ['suspended_reason' => $reason],
            );
        }

        $this->audit->log(
            userId: (int) $site['user_id'],
            action: 'claim',
            entityType: 'Site',
            entityId: (int) $site['id'],
            newValues: $actor + ['matched_via' => $site['matched_via']],
            ipAddress: $clientIp !== '' ? $clientIp : null,
            userAgent: $userAgent,
        );

        return ClaimResult::issued([
            'site_id' => (int) $site['id'],
            'domain' => (string) $site['domain'],
            'matched_domain' => $host,
            'matched_via' => (string) $site['matched_via'],
            'site_name' => (string) ($site['site_name'] ?? ''),
            'website_key' => (string) $site['website_key'],
            'status' => 'active',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{0: array{host: string, challenge_url: string, token: string, plugin: string, plugin_version: string}, 1: array<string, string>}
     */
    private function validate(array $payload): array
    {
        $str = static fn (string $key): string => is_string($payload[$key] ?? null) ? trim((string) $payload[$key]) : '';

        $rawDomain = $str('domain');
        $challengeUrl = $str('challenge_url');
        $token = strtolower($str('token'));
        $plugin = strtolower($str('plugin'));
        $pluginVersion = $str('plugin_version');

        $errors = [];
        $host = $rawDomain === '' ? '' : $this->domains->stripPort($this->domains->normalise($rawDomain));
        if ($host === '' || !$this->domains->isHostname($host)) {
            $errors['domain'] = 'A public hostname is required.';
        }
        if ($challengeUrl === '' || strlen($challengeUrl) > self::MAX_URL_LENGTH) {
            $errors['challenge_url'] = 'A challenge URL of at most 2048 characters is required.';
        }
        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            $errors['token'] = 'The token must be 64 hexadecimal characters.';
        }
        if (preg_match('/^[a-z0-9_-]{1,32}$/', $plugin) !== 1) {
            $errors['plugin'] = 'The plugin name is required.';
        }
        if ($pluginVersion !== '' && preg_match('/^[0-9A-Za-z.+-]{1,32}$/', $pluginVersion) !== 1) {
            $errors['plugin_version'] = 'The plugin version is malformed.';
        }

        return [
            ['host' => $host, 'challenge_url' => $challengeUrl, 'token' => $token, 'plugin' => $plugin, 'plugin_version' => $pluginVersion],
            $errors,
        ];
    }

    /** The challenge must live on the claimed domain (www or not), on http(s), on the default port, with no credentials. */
    private function challengeUrlError(string $url, string $host): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['host'])) {
            return 'The challenge URL could not be parsed.';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return 'The challenge URL must use http or https.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The challenge URL must not carry credentials.';
        }
        if (isset($parts['port']) && (int) $parts['port'] !== ($scheme === 'https' ? 443 : 80)) {
            return 'The challenge URL must use the default port.';
        }

        $urlHost = $this->domains->stripPort($this->domains->normalise((string) $parts['host']));
        if ($this->domains->stripWww($urlHost) !== $this->domains->stripWww($host)) {
            return 'The challenge URL must be on the claimed domain.';
        }

        return null;
    }

    /**
     * Live sites matching the host or its www twin, by primary domain first and
     * associated domain second, deduplicated by site id.
     *
     * @return list<array<string, mixed>>
     */
    private function candidates(string $host): array
    {
        $names = array_values(array_unique([$host, $this->domains->wwwTwin($host)]));

        $byId = [];
        foreach ($this->sites->findLiveByDomains($names) as $row) {
            $id = (int) $row['id'];
            if (!isset($byId[$id])) {
                $row['matched_via'] = ((string) $row['domain']) === $host ? 'primary' : 'www_twin';
                $byId[$id] = $row;
            }
        }
        foreach ($this->sites->findLiveByAssociatedDomains($names) as $row) {
            $id = (int) $row['id'];
            if (!isset($byId[$id])) {
                $row['matched_via'] = 'associated';
                $byId[$id] = $row;
            }
        }

        return array_values($byId);
    }

    /** @param array{headers: array<string, string>, body: string} $fetch */
    private function tokenMatches(string $token, array $fetch): bool
    {
        $body = strtolower(trim($fetch['body']));
        $header = strtolower(trim((string) ($fetch['headers']['x-conzent-claim'] ?? '')));

        return hash_equals($token, $body) || ($header !== '' && hash_equals($token, $header));
    }

    /** A never-seen query value so no cache between us and the site can answer for it. */
    private function withCacheBuster(string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'conzent_cb=' . bin2hex(random_bytes(8));
    }

    private function unreachable(string $host, string $challengeUrl, string $reason): ClaimResult
    {
        $this->logger->info('Plugin claim: ' . $host . ' unreachable at ' . $challengeUrl . ' (' . $reason . ')');

        return ClaimResult::failed(
            self::CODE_CHALLENGE_UNREACHABLE,
            409,
            sprintf('Conzent could not reach %s to confirm that this site controls %s.', $challengeUrl, $host),
            [],
            ['reason' => $reason],
        );
    }

    private function rateLimited(int $window): ClaimResult
    {
        return ClaimResult::failed(
            self::CODE_RATE_LIMITED,
            429,
            'Too many claim attempts. Try again later.',
            [],
            ['retry_after' => $window],
        );
    }
}
