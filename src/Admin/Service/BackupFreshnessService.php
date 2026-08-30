<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;
use OCI\Identity\Service\MailerService;
use Psr\Log\LoggerInterface;

/**
 * Watches the backup heartbeat so a silently dead backup job cannot go
 * unnoticed. The host's backup script stamps var/backup-last-success
 * (an ISO timestamp) after every successful run:
 *
 *   docker exec conzent-app-app-1 sh -c 'date -u +%FT%TZ > /var/www/html/var/backup-last-success'
 *
 * The monitor arms itself on the first stamp it ever sees (recorded in
 * oci_configuration), so installations without a wired backup script get
 * zero noise — but once armed, silence is treated as failure: a stamp
 * older than BACKUP_MAX_AGE_HOURS (default 26, one daily run + slack)
 * sends one alert email, and one recovery notice when stamps resume.
 */
final class BackupFreshnessService
{
    private const ARMED_KEY = 'backup_monitor_armed';
    private const ALERTED_KEY = 'backup_monitor_alerted_at';

    public function __construct(
        private readonly Connection $db,
        private readonly MailerService $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
    ) {}

    public function check(): void
    {
        $stampPath = $this->basePath . '/var/backup-last-success';
        $stamp = is_file($stampPath) ? trim((string) file_get_contents($stampPath)) : '';
        $stampTime = $stamp !== '' ? strtotime($stamp) : false;

        $armed = $this->getConfig(self::ARMED_KEY) === '1';

        if (!$armed) {
            if ($stampTime !== false) {
                $this->setConfig(self::ARMED_KEY, '1');
                $this->logger->info('Backup monitor armed - first backup heartbeat seen', ['stamp' => $stamp]);
            }

            return;
        }

        $maxAgeHours = max(1, (int) ($_ENV['BACKUP_MAX_AGE_HOURS'] ?? 26));
        $stale = $stampTime === false || $stampTime < strtotime("-{$maxAgeHours} hours");
        $alertedAt = $this->getConfig(self::ALERTED_KEY);

        if ($stale && $alertedAt === '') {
            $age = $stampTime !== false
                ? round((time() - $stampTime) / 3600) . ' hours ago'
                : 'never (stamp file missing)';
            $this->setConfig(self::ALERTED_KEY, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->logger->warning('Backup heartbeat is stale', ['last_success' => $stamp !== '' ? $stamp : 'missing']);
            $this->sendMail(
                'ALERT: Conzent backup has not completed',
                '<p>The backup heartbeat is stale: the last successful backup was <strong>' . $age . '</strong> '
                . '(threshold ' . $maxAgeHours . ' hours).</p>'
                . '<p>Check the backup job on the host and its log. You\'ll get a follow-up email when a backup completes again.</p>',
                "The backup heartbeat is stale: the last successful backup was {$age} (threshold {$maxAgeHours} hours).\n"
                . "Check the backup job on the host and its log.\n",
            );

            return;
        }

        if (!$stale && $alertedAt !== '') {
            $this->setConfig(self::ALERTED_KEY, '');
            $this->logger->info('Backup heartbeat recovered', ['stamp' => $stamp]);
            $this->sendMail(
                'Resolved: Conzent backup completed again',
                '<p>A backup completed successfully at <strong>' . $stamp . '</strong> after the earlier alert. No action needed.</p>',
                "A backup completed successfully at {$stamp} after the earlier alert. No action needed.\n",
            );
        }
    }

    private function sendMail(string $subject, string $html, string $text): void
    {
        $to = trim((string) ($_ENV['BACKUP_ALERT_EMAIL'] ?? ''));
        if ($to === '') {
            $to = trim((string) ($_ENV['SCANNER_ALERT_EMAIL'] ?? ''));
        }
        if ($to === '') {
            $to = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? 'support@getconzent.com'));
        }

        if (!$this->mailer->send($to, $subject, $html, $text)) {
            $this->logger->warning('Backup monitor mail failed to send', ['to' => $to, 'subject' => $subject]);
        }
    }

    private function getConfig(string $key): string
    {
        $value = $this->db->fetchOne(
            "SELECT config_value FROM oci_configuration WHERE scope = 'system' AND config_key = :key",
            ['key' => $key],
        );

        return \is_string($value) ? $value : '';
    }

    private function setConfig(string $key, string $value): void
    {
        $updated = $this->db->executeStatement(
            "UPDATE oci_configuration SET config_value = :val WHERE scope = 'system' AND config_key = :key",
            ['val' => $value, 'key' => $key],
        );
        if ($updated === 0) {
            $this->db->executeStatement(
                "INSERT INTO oci_configuration (scope, scope_id, config_key, config_value) VALUES ('system', NULL, :key, :val)",
                ['key' => $key, 'val' => $value],
            );
        }
    }
}
