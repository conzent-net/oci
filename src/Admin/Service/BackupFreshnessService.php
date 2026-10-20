<?php

declare(strict_types=1);

namespace OCI\Admin\Service;

use Doctrine\DBAL\Connection;
use OCI\Admin\Repository\BackupRepositoryInterface;
use OCI\Identity\Service\MailerService;
use Psr\Log\LoggerInterface;

/**
 * Watches backup freshness so a silently dead backup job cannot go unnoticed.
 *
 * Two sources, newest wins:
 *   1. oci_backups — the app's own runs, written by BackupService.
 *   2. var/backup-last-success — an ISO stamp a host script may write, kept
 *      so installs whose backups run outside the app still count.
 *
 * ── Why the arming rule changed (2026-09-04) ────────────────────────────────
 * This class shipped arming ONLY on the first heartbeat it ever saw, so that
 * installs without a wired backup made no noise. Nothing in the repository
 * ever wrote that heartbeat file — the docker exec line documented here was
 * never added to any backup script — so the monitor sat disarmed from the day
 * it shipped and could not have fired. A restore drill found it, months late,
 * alongside two other silent backup failures it was supposed to have caught.
 *
 * It now also arms when a backup SCHEDULE is configured. Configuring a
 * schedule is a statement that backups are expected, so silence after it is a
 * failure, not an absence of opinion. "Stay quiet until proven otherwise" is
 * the wrong default for an alarm: it fails toward silence, which is
 * indistinguishable from everything being fine.
 *
 * Once armed, silence is failure: a last-success older than
 * BACKUP_MAX_AGE_HOURS (default 26 — one daily run plus slack) sends one
 * alert email, and one recovery notice when backups resume.
 */
final class BackupFreshnessService
{
    private const ARMED_KEY = 'backup_monitor_armed';
    private const ALERTED_KEY = 'backup_monitor_alerted_at';
    private const SCHEDULE_KEY = 'backup_schedule_hour';

    public function __construct(
        private readonly Connection $db,
        private readonly BackupRepositoryInterface $backups,
        private readonly MailerService $mailer,
        private readonly LoggerInterface $logger,
        private readonly string $basePath,
        /** Test seam, as on SignupSendBreaker — MailerService is final. */
        private $notify = null,
    ) {}

    /**
     * Newest successful backup across both sources, or null if there is none.
     */
    public function lastSuccessAt(): ?\DateTimeImmutable
    {
        $times = [];

        try {
            $row = $this->backups->latestSuccessful();
            $finished = $row['finished_at'] ?? null;
            if (\is_string($finished) && $finished !== '') {
                $times[] = strtotime($finished);
            }
        } catch (\Throwable $e) {
            // The table may not exist yet on an install that has not migrated.
            $this->logger->debug('Backup history unavailable: ' . $e->getMessage());
        }

        $stampPath = $this->basePath . '/var/backup-last-success';
        if (is_file($stampPath)) {
            $stamp = trim((string) file_get_contents($stampPath));
            if ($stamp !== '' && ($t = strtotime($stamp)) !== false) {
                $times[] = $t;
            }
        }

        $times = array_filter($times, static fn ($t): bool => $t !== false);

        return $times === [] ? null : new \DateTimeImmutable('@' . max($times));
    }

    public function isScheduleConfigured(): bool
    {
        return $this->getConfig(self::SCHEDULE_KEY) !== '';
    }

    public function check(): void
    {
        $last = $this->lastSuccessAt();
        $stampTime = $last?->getTimestamp() ?? false;

        $armed = $this->getConfig(self::ARMED_KEY) === '1';

        if (!$armed) {
            // Either signal arms it: a backup actually happened, or someone
            // declared that backups are expected.
            if ($stampTime !== false || $this->isScheduleConfigured()) {
                $this->setConfig(self::ARMED_KEY, '1');
                $this->logger->info('Backup monitor armed', [
                    'reason' => $stampTime !== false ? 'first successful backup seen' : 'schedule configured',
                ]);
            } else {
                return;
            }
        }

        $maxAgeHours = max(1, (int) ($_ENV['BACKUP_MAX_AGE_HOURS'] ?? 26));
        $stale = $stampTime === false || $stampTime < strtotime("-{$maxAgeHours} hours");
        $alertedAt = $this->getConfig(self::ALERTED_KEY);

        if ($stale && $alertedAt === '') {
            $age = $stampTime !== false
                ? round((time() - $stampTime) / 3600) . ' hours ago'
                : 'never — no successful backup has ever been recorded';
            $this->setConfig(self::ALERTED_KEY, (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
            $this->logger->warning('Backup is stale', ['last_success' => $last?->format('c') ?? 'never']);
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
            $stampText = $last?->format('Y-m-d H:i') ?? 'an unknown time';
            $this->setConfig(self::ALERTED_KEY, '');
            $this->logger->info('Backup recovered', ['stamp' => $stampText]);
            $this->sendMail(
                'Resolved: Conzent backup completed again',
                '<p>A backup completed successfully at <strong>' . $stampText . '</strong> after the earlier alert. No action needed.</p>',
                "A backup completed successfully at {$stampText} after the earlier alert. No action needed.\n",
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

        $send = $this->notify ?? fn (string $t, string $s, string $h, string $x): bool => $this->mailer->send($t, $s, $h, $x);

        if (!$send($to, $subject, $html, $text)) {
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
