<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

use Doctrine\DBAL\Connection;
use OCI\Cookie\Repository\CookieRegisterRepositoryInterface;
use OCI\Identity\Service\MailerService;
use OCI\Report\Repository\ReportRepositoryInterface;
use OCI\Scanning\Repository\BeaconClassificationRepositoryInterface;
use OCI\Scanning\Repository\ScanRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Log\LoggerInterface;
use Twig\Environment as TwigEnvironment;

/**
 * Snapshots the EFFECTIVE cookie/beacon register after every completed scan
 * and diffs it against the previous snapshot.
 *
 * "Effective" means what the site actually serves its visitors: latest scan
 * cookies merged with client observations, enriched from the global
 * database, with per-site user overrides applied — exactly what the
 * /cookies page shows — plus the scan's beacons resolved through the
 * classification index. The first snapshot of a site is the baseline and
 * emits no changes.
 */
final class CookieRegisterDiffService
{
    /** oci_configuration key for the per-site alert toggle (absent = on). */
    public const ALERT_CONFIG_KEY = 'cookie_register_alerts';

    public function __construct(
        private readonly CookieService $cookieService,
        private readonly CookieRegisterRepositoryInterface $registerRepo,
        private readonly ScanRepositoryInterface $scanRepo,
        private readonly BeaconClassificationRepositoryInterface $beaconRepo,
        private readonly ReportRepositoryInterface $reportRepo,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly MailerService $mailer,
        private readonly TwigEnvironment $twig,
        private readonly Connection $db,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Snapshot the current effective register, diff against the previous
     * snapshot, persist the changes, and send the alert mail when enabled.
     *
     * @return array{snapshot_id: int, baseline: bool, changes: list<array<string, mixed>>}
     */
    public function snapshotAndDiff(int $siteId, ?int $scanId = null): array
    {
        $entries = $this->buildRegister($siteId, $scanId);
        $previous = $this->registerRepo->getLatestSnapshot($siteId);

        $snapshotId = $this->registerRepo->saveSnapshot($siteId, $scanId, $entries);

        if ($previous === null) {
            return ['snapshot_id' => $snapshotId, 'baseline' => true, 'changes' => []];
        }

        $changes = $this->diff($previous['entries'], $entries);
        if ($changes === []) {
            return ['snapshot_id' => $snapshotId, 'baseline' => false, 'changes' => []];
        }

        $ids = $this->registerRepo->insertChanges($siteId, $snapshotId, $scanId, $changes);

        if ($this->alertsEnabled($siteId)) {
            $this->sendAlert($siteId, $changes, $ids);
        }

        return ['snapshot_id' => $snapshotId, 'baseline' => false, 'changes' => $changes];
    }

    /**
     * Build the normalized effective register: cookies + beacons.
     *
     * @return list<array<string, mixed>>
     */
    private function buildRegister(int $siteId, ?int $scanId): array
    {
        $entries = [];

        $grouped = $this->cookieService->getCookiesGroupedByCategory($siteId);
        foreach ($grouped['cookiesByCategory'] as $slug => $cookies) {
            foreach ($cookies as $cookie) {
                $entries[] = [
                    'type' => 'cookie',
                    'name' => mb_strtolower(trim((string) ($cookie['cookie_name'] ?? ''))),
                    'domain' => mb_strtolower(trim((string) ($cookie['cookie_domain'] ?? ''))),
                    'category' => (string) $slug,
                    'attrs' => [
                        'http_only' => self::triState($cookie['http_only'] ?? null),
                        'secure' => self::triState($cookie['secure'] ?? null),
                        'same_site' => isset($cookie['same_site']) && $cookie['same_site'] !== null && $cookie['same_site'] !== ''
                            ? strtolower((string) $cookie['same_site'])
                            : null,
                    ],
                ];
            }
        }

        // Beacons ride the scan that triggered the snapshot. Identity is the
        // normalized host+path (query stripped), matching the classification
        // model — one domain can serve scripts of different categories.
        if ($scanId !== null) {
            $beacons = $this->beaconRepo->resolveBeacons($this->scanRepo->getBeaconsByScan($scanId));
            $seen = [];
            foreach ($beacons as $beacon) {
                $name = self::beaconName((string) ($beacon['beacon_url'] ?? ''));
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $entries[] = [
                    'type' => 'beacon',
                    'name' => $name,
                    'domain' => mb_strtolower(trim((string) ($beacon['domain'] ?? ''))),
                    'category' => (string) ($beacon['resolved_slug'] ?? 'unclassified'),
                    'attrs' => [],
                ];
            }
        }

        return $entries;
    }

    /**
     * Diff two normalized registers keyed by (type, name, domain).
     *
     * @param list<array<string, mixed>> $old
     * @param list<array<string, mixed>> $new
     * @return list<array<string, mixed>>
     */
    private function diff(array $old, array $new): array
    {
        $oldIndex = self::index($old);
        $newIndex = self::index($new);
        $changes = [];

        foreach ($newIndex as $key => $entry) {
            if (!isset($oldIndex[$key])) {
                $changes[] = self::change('added', $entry, null, $entry);
                continue;
            }

            $prev = $oldIndex[$key];
            if (($prev['category'] ?? '') !== ($entry['category'] ?? '')) {
                $changes[] = self::change('category_changed', $entry, $prev, $entry);
            }

            // Attribute flips only when BOTH sides know the attribute — a
            // client-observed cookie (attrs null) gaining real values on its
            // first server scan is enrichment, not a change.
            foreach (($entry['attrs'] ?? []) as $attr => $value) {
                $prevValue = $prev['attrs'][$attr] ?? null;
                if ($prevValue !== null && $value !== null && $prevValue !== $value) {
                    $changes[] = self::change('attribute_changed', $entry, $prev, $entry);
                    break;
                }
            }
        }

        foreach ($oldIndex as $key => $entry) {
            if (!isset($newIndex[$key])) {
                $changes[] = self::change('removed', $entry, $entry, null);
            }
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     * @return array<string, mixed>
     */
    private static function change(string $type, array $entry, ?array $old, ?array $new): array
    {
        return [
            'change_type' => $type,
            'entry_type' => $entry['type'],
            'name' => $entry['name'],
            'domain' => $entry['domain'] ?? '',
            'old_value' => $old,
            'new_value' => $new,
        ];
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, array<string, mixed>>
     */
    private static function index(array $entries): array
    {
        $index = [];
        foreach ($entries as $entry) {
            $key = ($entry['type'] ?? '') . '|' . ($entry['name'] ?? '') . '|' . ($entry['domain'] ?? '');
            $index[$key] = $entry;
        }

        return $index;
    }

    private static function triState(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) (bool) $value;
    }

    private static function beaconName(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url(str_contains($url, '://') ? $url : 'http://' . $url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return mb_strtolower($url);
        }

        return mb_substr($host . rtrim((string) ($parts['path'] ?? ''), '/'), 0, 500);
    }

    private function alertsEnabled(int $siteId): bool
    {
        try {
            $value = $this->db->fetchOne(
                "SELECT config_value FROM oci_configuration
                 WHERE scope = 'site' AND scope_id = :siteId AND config_key = :key",
                ['siteId' => $siteId, 'key' => self::ALERT_CONFIG_KEY],
            );
        } catch (\Throwable) {
            return true;
        }

        // Absent means ON — a register change is compliance-relevant by default
        return $value === false || (string) $value !== '0';
    }

    public function setAlertsEnabled(int $siteId, bool $enabled): void
    {
        $this->db->executeStatement(
            "INSERT INTO oci_configuration (scope, scope_id, config_key, config_value)
             VALUES ('site', :siteId, :key, :value)
             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)",
            ['siteId' => $siteId, 'key' => self::ALERT_CONFIG_KEY, 'value' => $enabled ? '1' : '0'],
        );
    }

    public function areAlertsEnabled(int $siteId): bool
    {
        return $this->alertsEnabled($siteId);
    }

    /**
     * @param list<array<string, mixed>> $changes
     * @param list<int> $changeIds
     */
    private function sendAlert(int $siteId, array $changes, array $changeIds): void
    {
        try {
            $site = $this->siteRepo->findById($siteId);
            $domain = (string) ($site['domain'] ?? '');

            $schedule = $this->reportRepo->getSchedule($siteId, 'scan');
            $to = $schedule['email_to'] ?? null;
            if ($to === null || $to === '') {
                $to = $this->reportRepo->getSiteOwnerEmail($siteId);
            }
            if ($to === null || $to === '') {
                $this->logger->warning('Register changes detected but no alert recipient', ['site_id' => $siteId]);

                return;
            }

            $counts = ['added' => 0, 'removed' => 0, 'category_changed' => 0, 'attribute_changed' => 0];
            foreach ($changes as $change) {
                $counts[$change['change_type']] = ($counts[$change['change_type']] ?? 0) + 1;
            }

            $html = $this->twig->render('emails/register-changes.html.twig', [
                'domain' => $domain,
                'changes' => $changes,
                'counts' => $counts,
                'total' => \count($changes),
                'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'base_url' => rtrim($_ENV['APP_URL'] ?? '', '/'),
            ]);

            $subject = 'Cookie register changed on ' . $domain . ' — ' . \count($changes) . ' change' . (\count($changes) === 1 ? '' : 's');

            if ($this->mailer->send($to, $subject, $html)) {
                $this->registerRepo->markNotified($changeIds);
            }
        } catch (\Throwable $e) {
            // The change rows are already persisted — a mail failure loses
            // nothing; the changes still show on /cookies/changes.
            $this->logger->error('Register change alert failed', [
                'site_id' => $siteId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
