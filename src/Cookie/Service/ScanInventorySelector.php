<?php

declare(strict_types=1);

namespace OCI\Cookie\Service;

/**
 * Which scan a site's cookie list and public cookie declaration come from.
 *
 * Until two-phase scans this was "the latest completed scan that found any
 * cookie". After a customer installs Conzent, a scan run without consent sees
 * only what the blocker lets through — our own two cookies — so the first scan
 * after install quietly shrank the declaration every visitor is shown.
 *
 * One rule, used by every reader:
 *   1. the newest scan whose after-consent inventory is complete and verified,
 *      read from its after-consent rows;
 *   2. otherwise the newest single-phase scan, read from its rows as before;
 *   3. never the before-consent rows of a two-phase scan. Those answer "what
 *      leaks before consent", and using them as the inventory is precisely
 *      the regression this rule exists to end.
 *
 * A verified inventory therefore outranks a newer single-phase scan: while the
 * after-consent pass is limited to admins, the scan an admin verified stays
 * the site's inventory until a newer verified one replaces it.
 */
final class ScanInventorySelector
{
    public const BASIS_INVENTORY = 'inventory';
    public const BASIS_LEGACY = 'legacy';

    public const PHASE_POST_CONSENT = 'post_consent';

    /**
     * @param list<array<string, mixed>> $candidates completed scans of one site, newest first, each with
     *        `id`, `inventory_complete`, `has_inventory_rows` and `has_legacy_rows`
     *
     * @return array{scan_id: int, phase: ?string, basis: string}|null
     */
    public static function select(array $candidates): ?array
    {
        foreach ($candidates as $scan) {
            if ((int) ($scan['inventory_complete'] ?? 0) === 1 && (int) ($scan['has_inventory_rows'] ?? 0) === 1) {
                return ['scan_id' => (int) $scan['id'], 'phase' => self::PHASE_POST_CONSENT, 'basis' => self::BASIS_INVENTORY];
            }
        }

        foreach ($candidates as $scan) {
            if ((int) ($scan['has_legacy_rows'] ?? 0) === 1) {
                return ['scan_id' => (int) $scan['id'], 'phase' => null, 'basis' => self::BASIS_LEGACY];
            }
        }

        return null;
    }
}
