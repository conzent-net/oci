<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Alert;

use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Sends one event to the operator's gap list: something a person has to act
 * on, such as an API key a provider stopped accepting.
 *
 * Does nothing when GAPS_WEBHOOK_URL or GAPS_WEBHOOK_TOKEN is empty (local
 * dev, CI, self-hosted installs). Never throws and never waits more than a
 * few seconds, so it is safe to call from a failure path.
 *
 * Redis remembers which events are open. A condition reported again and again
 * (every banner save while a token is dead) posts at most once per
 * REFRESH_SECONDS, and resolve() only posts for an event this install opened.
 * Without Redis, open() still posts and resolve() stays quiet: the event then
 * closes by its own TTL.
 *
 * Titles are read aloud and shown on a screen: generic words only, never a
 * customer, a domain, a secret or text a visitor typed.
 */
final class GapWebhook
{
    private const STATE_PREFIX = 'gap:open:';
    private const REFRESH_SECONDS = 6 * 3600;
    private const STATE_TTL_SECONDS = 7 * 86400;

    /** @var callable(string, string, string): int */
    private $transport;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?RedisClient $redis = null,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::post(...);
    }

    /** 1 = money or customers hurt now, 2 = needs attention today, 3 = card only. */
    public function open(string $key, int $severity, string $title, ?string $detail = null, int $ttlHours = 72): void
    {
        $sentAt = $this->state($key);
        if ($sentAt !== null && time() - $sentAt < self::REFRESH_SECONDS) {
            return;
        }

        $event = ['key' => $key, 'severity' => $severity, 'title' => $title, 'detail' => $detail, 'ttl_hours' => $ttlHours];
        if ($this->send($event)) {
            $this->remember($key, time());
        }
    }

    public function resolve(string $key): void
    {
        if ($this->state($key) === null) {
            return;
        }

        if ($this->send(['key' => $key, 'state' => 'resolved'])) {
            $this->remember($key, null);
        }
    }

    /** @param array<string, mixed> $event */
    private function send(array $event): bool
    {
        $url = self::env('GAPS_WEBHOOK_URL');
        $token = self::env('GAPS_WEBHOOK_TOKEN');
        if ($url === '' || $token === '') {
            return false;
        }

        $body = (string) json_encode(array_filter($event, static fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE);

        try {
            $status = ($this->transport)($url, $token, $body);
        } catch (\Throwable $e) {
            $status = 0;
        }

        if ($status !== 200) {
            $this->logger->warning('Gap webhook not accepted', ['http_code' => $status, 'key' => $event['key'] ?? '?']);

            return false;
        }

        return true;
    }

    /** When this install last posted the event as open, or null if it is not open. */
    private function state(string $key): ?int
    {
        if ($this->redis === null) {
            return null;
        }

        try {
            $value = $this->redis->get(self::STATE_PREFIX . $key);
        } catch (\Throwable) {
            return null;
        }

        return $value === null ? null : (int) $value;
    }

    private function remember(string $key, ?int $sentAt): void
    {
        if ($this->redis === null) {
            return;
        }

        try {
            if ($sentAt === null) {
                $this->redis->del([self::STATE_PREFIX . $key]);
            } else {
                $this->redis->setex(self::STATE_PREFIX . $key, self::STATE_TTL_SECONDS, (string) $sentAt);
            }
        } catch (\Throwable) {
            // Alerting must never break the caller.
        }
    }

    private static function env(string $name): string
    {
        return trim((string) ($_ENV[$name] ?? getenv($name) ?: ''));
    }

    private static function post(string $url, string $token, string $body): int
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $status;
    }
}
