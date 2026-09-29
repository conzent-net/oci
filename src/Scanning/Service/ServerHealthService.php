<?php

declare(strict_types=1);

namespace OCI\Scanning\Service;

use OCI\Identity\Service\MailerService;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Polls each active scan server's /health endpoint, records heartbeats, and
 * emails an alert when a server goes down — and again when it recovers.
 *
 * Wired into the schedule:run loop (every ~5 min) and runnable on demand via
 * `php bin/oci scanner:health`. Alerts are de-duplicated through the
 * down_alerted_at column so a single outage produces exactly one "down" email
 * and one "recovered" email — never one per polling cycle.
 *
 * Config (all optional, read from the environment / .env):
 *   SCANNER_ALERT_EMAIL     Recipient for alerts (falls back to MAIL_FROM_ADDRESS).
 *   SCANNER_ALERT_FAILURES  Consecutive failures before the first alert (default 2).
 */
final class ServerHealthService
{
    private const DEFAULT_FAILURE_THRESHOLD = 2;

    public function __construct(
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly MailerService $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Check every active scan server once.
     *
     * @return array<int, array{id:int, name:string, url:string, healthy:bool, http_code:int, error:string, alerted:string}>
     */
    public function checkAll(): array
    {
        $results = [];

        foreach ($this->scanRepo->getAllScanServers() as $server) {
            if ((int) $server['is_active'] !== 1) {
                continue;
            }
            $results[] = $this->checkServer($server);
        }

        return $results;
    }

    /**
     * Poll one server, persist its new state, and fire an alert/recovery email
     * on a state transition.
     *
     * @param array<string, mixed> $server
     * @return array{id:int, name:string, url:string, healthy:bool, http_code:int, error:string, alerted:string}
     */
    private function checkServer(array $server): array
    {
        $id   = (int) $server['id'];
        $name = (string) $server['server_name'];
        $url  = rtrim((string) $server['server_url'], '/');
        $now  = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        [$ok, $httpCode, $error] = $this->ping($url, (string) ($server['api_key'] ?? ''));

        $update  = ['last_health_check_at' => $now];
        $alerted = 'none';

        if ($ok) {
            $update['last_heartbeat_at']    = $now;
            $update['consecutive_failures'] = 0;

            // Recovery: we previously alerted that this server was down.
            if ($server['down_alerted_at'] !== null) {
                $update['down_alerted_at'] = null;
                $alerted = 'recovered';
            }
        } else {
            $failures = (int) ($server['consecutive_failures'] ?? 0) + 1;
            $update['consecutive_failures'] = $failures;

            if ($failures >= $this->failureThreshold() && $server['down_alerted_at'] === null) {
                $update['down_alerted_at'] = $now;
                $alerted = 'down';
            }
        }

        // Persist BEFORE emailing: a mailer hiccup must not cause the same
        // alert to re-fire next cycle. We log any send failure instead.
        $this->scanRepo->updateScanServer($id, $update);

        if ($alerted === 'down') {
            $this->logger->warning("Scan server '{$name}' ({$url}) is DOWN (http={$httpCode}): {$error}");
            $this->sendDownEmail($name, $url, (int) $update['consecutive_failures'], $httpCode, $error);
        } elseif ($alerted === 'recovered') {
            $this->logger->info("Scan server '{$name}' ({$url}) recovered");
            $this->sendRecoveryEmail($name, $url, (string) $server['down_alerted_at']);
        } elseif (!$ok) {
            $this->logger->warning(
                "Scan server '{$name}' ({$url}) health check failed "
                . "(#{$update['consecutive_failures']}, http={$httpCode}): {$error}",
            );
        }

        return [
            'id'        => $id,
            'name'      => $name,
            'url'       => $url,
            'healthy'   => $ok,
            'http_code' => $httpCode,
            'error'     => $error,
            'alerted'   => $alerted,
        ];
    }

    /**
     * GET {url}/health. Success = HTTP 200 with a `{"status":"ok",...}` body.
     *
     * @return array{0:bool, 1:int, 2:string} [ok, httpCode, error]
     */
    private function ping(string $baseUrl, string $apiKey): array
    {
        $ch = curl_init($baseUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $apiKey !== '' ? ['X-Api-Key: ' . $apiKey] : [],
        ]);

        $body     = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = (string) curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return [false, $httpCode, $curlErr];
        }
        if ($httpCode !== 200) {
            return [false, $httpCode, "HTTP {$httpCode}"];
        }

        $data = json_decode((string) $body, true);
        if (!\is_array($data) || ($data['status'] ?? '') !== 'ok') {
            return [false, $httpCode, 'Unexpected health payload'];
        }

        return [true, $httpCode, ''];
    }

    private function failureThreshold(): int
    {
        $t = (int) ($_ENV['SCANNER_ALERT_FAILURES'] ?? self::DEFAULT_FAILURE_THRESHOLD);

        return $t > 0 ? $t : 1;
    }

    private function alertRecipient(): string
    {
        $to = trim((string) ($_ENV['SCANNER_ALERT_EMAIL'] ?? ''));
        if ($to === '') {
            $to = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? 'support@getconzent.com'));
        }

        return $to;
    }

    private function appUrl(): string
    {
        return rtrim((string) ($_ENV['APP_URL'] ?? 'http://localhost:8100'), '/');
    }

    private function sendDownEmail(string $name, string $url, int $failures, int $httpCode, string $error): void
    {
        $to     = $this->alertRecipient();
        $when   = (new \DateTimeImmutable())->format('Y-m-d H:i:s T');
        $detail = $error !== '' ? $error : "HTTP {$httpCode}";
        $admin  = $this->appUrl() . '/admin/servers';

        $subject = "\u{26A0} Scan server DOWN: {$name}";

        $html = '<h2 style="color:#c0392b">Scan server is not responding</h2>'
            . '<p>The cookie scanner failed its health check and appears to be offline. '
            . 'Cookie scans will not run until it recovers.</p>'
            . '<table cellpadding="6" style="border-collapse:collapse;font-family:sans-serif">'
            . '<tr><td><strong>Server</strong></td><td>' . htmlspecialchars($name) . '</td></tr>'
            . '<tr><td><strong>Endpoint</strong></td><td>' . htmlspecialchars($url) . '/health</td></tr>'
            . '<tr><td><strong>Error</strong></td><td>' . htmlspecialchars($detail) . '</td></tr>'
            . '<tr><td><strong>Consecutive failures</strong></td><td>' . $failures . '</td></tr>'
            . '<tr><td><strong>Detected at</strong></td><td>' . htmlspecialchars($when) . '</td></tr>'
            . '</table>'
            . '<p>Manage servers at <a href="' . $admin . '">' . $admin . '</a>.</p>'
            . '<p>You\'ll get a follow-up email when it comes back online.</p>';

        $text = "Scan server DOWN: {$name}\n"
            . "Endpoint: {$url}/health\n"
            . "Error: {$detail}\n"
            . "Consecutive failures: {$failures}\n"
            . "Detected at: {$when}\n"
            . "Manage: {$admin}\n";

        if ($this->mailer->send($to, $subject, $html, $text)) {
            $this->logger->info("Scan server DOWN alert emailed to {$to} for '{$name}'");
        } else {
            $this->logger->error("Failed to email scan server DOWN alert to {$to} for '{$name}'");
        }
    }

    private function sendRecoveryEmail(string $name, string $url, string $downSince): void
    {
        $to   = $this->alertRecipient();
        $when = (new \DateTimeImmutable())->format('Y-m-d H:i:s T');

        $subject = "\u{2705} Scan server recovered: {$name}";

        $html = '<h2 style="color:#27ae60">Scan server is back online</h2>'
            . '<table cellpadding="6" style="border-collapse:collapse;font-family:sans-serif">'
            . '<tr><td><strong>Server</strong></td><td>' . htmlspecialchars($name) . '</td></tr>'
            . '<tr><td><strong>Endpoint</strong></td><td>' . htmlspecialchars($url) . '/health</td></tr>'
            . '<tr><td><strong>Was down since</strong></td><td>' . htmlspecialchars($downSince) . '</td></tr>'
            . '<tr><td><strong>Recovered at</strong></td><td>' . htmlspecialchars($when) . '</td></tr>'
            . '</table>'
            . '<p>Cookie scanning has resumed.</p>';

        $text = "Scan server recovered: {$name}\n"
            . "Endpoint: {$url}/health\n"
            . "Was down since: {$downSince}\n"
            . "Recovered at: {$when}\n";

        $this->mailer->send($to, $subject, $html, $text);
    }
}
