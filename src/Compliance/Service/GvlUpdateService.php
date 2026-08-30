<?php

declare(strict_types=1);

namespace OCI\Compliance\Service;

use OCI\Compliance\DTO\GvlUpdateResult;
use OCI\Compliance\Exception\GvlUpdateException;
use Psr\Log\LoggerInterface;

/**
 * Keeps the IAB Global Vendor List on disk fresh.
 *
 * Replaces the legacy `app/cron/update_gvl.php`, which was never ported — the
 * shipped list had been frozen at vendorListVersion 66 (Aug 2024) with no
 * refresh path, so every GVL-driven disclosure was stale.
 *
 * TCF v2.4 (web deadline 2026-10-23) is delivered almost entirely through GVL
 * data: the new top-level `standardTexts` field, populated `illustrations` on
 * Features, and the renamed Special Feature 2. Keeping this list current is
 * therefore the primary compliance mechanism, and it needs no script
 * regeneration — the consent bundle fetches `/iab/gvl/` at runtime.
 *
 * Safety model:
 *   - Validation runs entirely on the decoded in-memory payload. Disk is only
 *     touched by rename() of a fully-written temp file, so a failed or partial
 *     fetch always leaves the previously published list serving unchanged.
 *   - `gvlSpecificationVersion` and `tcfPolicyVersion` are pinned. A policy
 *     bump would invalidate every stored TC string and re-prompt the entire
 *     user base, so it must be a reviewed change, never a silent one.
 */
final class GvlUpdateService
{
    public const DEFAULT_SOURCE     = 'https://vendor-list.consensu.org/v3/';
    public const DEFAULT_ATP_SOURCE = 'https://storage.googleapis.com/tcfac/additional-consent-providers.csv';

    /**
     * Pinned so an upstream schema or policy change becomes a loud failure
     * rather than a silent fleet-wide re-consent. See guarantee #3 in the
     * TCF v2.4 backward-compatibility contract.
     */
    private const EXPECTED_SPEC_VERSION   = 3;
    private const EXPECTED_POLICY_VERSION = 5;

    /** Sanity floors — the live list carries ~1,200 vendors and ~900 KB. */
    private const MIN_VENDORS  = 400;
    private const MIN_BYTES    = 200_000;
    private const MIN_ATP_ROWS = 100;

    private const REQUIRED_KEYS = [
        'gvlSpecificationVersion', 'vendorListVersion', 'tcfPolicyVersion', 'lastUpdated',
        'purposes', 'specialPurposes', 'features', 'specialFeatures', 'stacks', 'dataCategories', 'vendors',
    ];

    private const REQUIRED_TRANSLATION_KEYS = [
        'purposes', 'specialPurposes', 'features', 'specialFeatures', 'stacks', 'dataCategories',
    ];

    /**
     * The 36 TCF consent languages. Must stay in step with `je.langSet` in
     * resources/consent/js/iab-script.js — the bundle refuses any other code.
     */
    public const TCF_LANGUAGES = [
        'ar', 'bg', 'bs', 'ca', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'eu',
        'fi', 'fr', 'gl', 'hr', 'hu', 'it', 'ja', 'lt', 'lv', 'mt', 'nl', 'no',
        'pl', 'pt-br', 'pt-pt', 'ro', 'ru', 'sk', 'sl', 'sr-latn', 'sr-cyrl',
        'sv', 'tr', 'zh',
    ];

    /** Filename the consent bundle actually requests (iab-script.js:1702). */
    private const PUBLISHED_FILENAME = 'vendor-list-v3.json';

    /**
     * @iabtcf's built-in default filename. Nothing we generate points here —
     * the bundle overrides it — but the path is publicly served, so we publish
     * the same payload to it rather than leave a stale list reachable.
     */
    private const ALIAS_FILENAME = 'vendor-list.json';

    private const ATP_FILENAME    = 'google-atp-list.json';
    private const STATUS_FILENAME = 'gvl-status.json';

    private readonly string $gvlPath;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly GvlHttpClientInterface $http,
        ?string $gvlPath = null,
    ) {
        $this->gvlPath = rtrim($gvlPath ?? \dirname(__DIR__, 3) . '/public/iab/gvl', '/\\');
    }

    // ── Configuration ────────────────────────────────────────────────

    /**
     * Air-gapped and mirror-pinned installs set GVL_AUTO_UPDATE=false to keep
     * whatever list they shipped with.
     */
    public function isEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['GVL_AUTO_UPDATE'] ?? 'true')));

        return !\in_array($flag, ['false', '0', 'off', 'no'], true);
    }

    public function sourceUrl(): string
    {
        $url = trim((string) ($_ENV['GVL_SOURCE_URL'] ?? ''));

        if ($url === '') {
            return self::DEFAULT_SOURCE;
        }

        return rtrim($url, '/') . '/';
    }

    /**
     * Metadata for the currently published list.
     *
     * Prefers the sidecar status file so a health check doesn't have to decode
     * ~900 KB of JSON; falls back to the list itself before the first run.
     *
     * @return array{version: int, lastUpdated: string, specVersion: int, policyVersion: int, hasStandardTexts: bool, vendorCount: int, googleVendorCount: int}|null
     */
    public function currentVersion(): ?array
    {
        $status = $this->readJsonFile($this->gvlPath . '/' . self::STATUS_FILENAME);

        if ($status !== null && isset($status['version'])) {
            return [
                'version'           => (int) $status['version'],
                'lastUpdated'       => (string) ($status['lastUpdated'] ?? ''),
                'specVersion'       => (int) ($status['specVersion'] ?? 0),
                'policyVersion'     => (int) ($status['policyVersion'] ?? 0),
                'hasStandardTexts'  => (bool) ($status['hasStandardTexts'] ?? false),
                'vendorCount'       => (int) ($status['vendorCount'] ?? 0),
                'googleVendorCount' => (int) ($status['googleVendorCount'] ?? 0),
            ];
        }

        $list = $this->readJsonFile($this->gvlPath . '/' . self::PUBLISHED_FILENAME);

        if ($list === null || !isset($list['vendorListVersion'])) {
            return null;
        }

        return [
            'version'           => (int) $list['vendorListVersion'],
            'lastUpdated'       => (string) ($list['lastUpdated'] ?? ''),
            'specVersion'       => (int) ($list['gvlSpecificationVersion'] ?? 0),
            'policyVersion'     => (int) ($list['tcfPolicyVersion'] ?? 0),
            'hasStandardTexts'  => !empty($list['standardTexts']),
            'vendorCount'       => \is_array($list['vendors'] ?? null) ? \count($list['vendors']) : 0,
            'googleVendorCount' => \is_array($list['googleVendors'] ?? null) ? \count($list['googleVendors']) : 0,
        ];
    }

    // ── Main entry point ─────────────────────────────────────────────

    /**
     * Fetch, validate and publish the latest vendor list.
     *
     * @throws GvlUpdateException on any validation failure (nothing written)
     */
    public function update(bool $force = false, bool $dryRun = false): GvlUpdateResult
    {
        if (!$this->isEnabled()) {
            return GvlUpdateResult::disabled();
        }

        $warnings = [];
        $current  = $this->currentVersion();
        $localVer = $current['version'] ?? 0;

        $url  = $this->sourceUrl() . 'vendor-list.json';
        $body = $this->http->get($url);

        if ($body === null) {
            throw new GvlUpdateException("Could not fetch the vendor list from {$url}. Previous list left in place.");
        }

        $data = $this->decodeAndValidate($body, $localVer, $force);

        $remoteVer = (int) $data['vendorListVersion'];

        if (!$force && $remoteVer === $localVer) {
            return GvlUpdateResult::upToDate(
                $localVer,
                (string) $data['lastUpdated'],
                \count($data['vendors']),
                $current['googleVendorCount'] ?? 0,
                !empty($data['standardTexts']),
            );
        }

        if (empty($data['standardTexts'])) {
            $warnings[] = 'Upstream list has no "standardTexts" field — TCF v2.4 Feature disclosure will not render.';
        }

        // ── Google Additional Consent providers ──────────────────────
        // Refreshed opportunistically: an ATP failure must never block a GVL
        // update, so we always fall back to whatever is already on disk.
        if ($this->atpRefreshEnabled() && !$dryRun) {
            if (!$this->refreshGoogleAtpList()) {
                $warnings[] = 'Google ATP provider list could not be refreshed — kept the existing copy.';
            }
        }

        $googleVendors = $this->readJsonFile($this->gvlPath . '/' . self::ATP_FILENAME) ?? [];

        if ($googleVendors === []) {
            $warnings[] = 'Google ATP provider list is empty or missing — the Google vendor tab will be empty.';
        }

        // The bundle reads this non-spec key in GVL.populate() (iab-script.js:1311).
        // Preserve the exact legacy contract.
        $merged = $data;
        $merged['googleVendors'] = $googleVendors;

        $json = json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($dryRun) {
            return new GvlUpdateResult(
                updated: false,
                skipped: true,
                reason: "Dry run — validated v{$remoteVer}, would update from v{$localVer}. Nothing written.",
                previousVersion: $localVer,
                newVersion: $remoteVer,
                lastUpdated: (string) $data['lastUpdated'],
                vendorCount: \count($data['vendors']),
                googleVendorCount: \count($googleVendors),
                hasStandardTexts: !empty($data['standardTexts']),
                warnings: $warnings,
            );
        }

        $this->writeAtomic($this->gvlPath . '/' . self::PUBLISHED_FILENAME, $json);
        $this->writeAtomic($this->gvlPath . '/' . self::ALIAS_FILENAME, $json);
        $this->archive($remoteVer, $json);
        $this->pruneArchives();
        $this->writeStatus($data, \count($googleVendors));

        $this->logger->info(
            "GVL updated: vendorListVersion {$localVer} → {$remoteVer} "
            . '(' . \count($data['vendors']) . ' vendors, '
            . \count($googleVendors) . ' Google ATP providers, '
            . 'standardTexts: ' . (empty($data['standardTexts']) ? 'no' : 'yes') . ')',
        );

        return new GvlUpdateResult(
            updated: true,
            reason: '',
            previousVersion: $localVer,
            newVersion: $remoteVer,
            lastUpdated: (string) $data['lastUpdated'],
            vendorCount: \count($data['vendors']),
            googleVendorCount: \count($googleVendors),
            hasStandardTexts: !empty($data['standardTexts']),
            warnings: $warnings,
        );
    }

    // ── Translations ─────────────────────────────────────────────────

    /**
     * Fetch the per-language purpose/feature translations.
     *
     * The consent bundle requests these as `purposes-[LANG].json` with a
     * lowercased, hyphenated code (iab-script.js:1296), so filenames must match
     * exactly. A 404 is normal for languages IAB has not published and is never
     * fatal.
     *
     * @param  list<string>       $langs Defaults to GVL_LANGUAGES, else all 36
     * @return array<string,bool> Language code => written
     */
    public function updateTranslations(array $langs = []): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $results = [];

        foreach (($langs === [] ? $this->targetLanguages() : $langs) as $lang) {
            $lang = strtolower(trim($lang));

            if ($lang === '') {
                continue;
            }

            $results[$lang] = $this->updateTranslation($lang);
        }

        return $results;
    }

    private function updateTranslation(string $lang): bool
    {
        $url  = $this->sourceUrl() . "purposes-{$lang}.json";
        $body = $this->http->get($url);

        if ($body === null) {
            $this->logger->info("GVL translation unavailable, skipped: {$lang}");

            return false;
        }

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning("GVL translation {$lang}: malformed JSON — " . $e->getMessage());

            return false;
        }

        if (!\is_array($data)) {
            return false;
        }

        foreach (self::REQUIRED_TRANSLATION_KEYS as $key) {
            if (!isset($data[$key]) || !\is_array($data[$key])) {
                $this->logger->warning("GVL translation {$lang}: missing \"{$key}\" — rejected.");

                return false;
            }
        }

        // Rejects the pre-TCF-2.2 file shape. The `da` file shipped with the
        // repo is a TCF v2.0 vintage with no illustrations and no
        // dataCategories; feeding it to the bundle throws in ot()/it_p() and
        // takes down the whole preference centre.
        if (!isset($data['purposes'][1]['illustrations']) || !\is_array($data['purposes'][1]['illustrations'])) {
            $this->logger->warning("GVL translation {$lang}: legacy shape (no illustrations) — rejected.");

            return false;
        }

        $this->writeAtomic(
            $this->gvlPath . "/purposes-{$lang}.json",
            json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return true;
    }

    /**
     * @return list<string>
     */
    private function targetLanguages(): array
    {
        $configured = trim((string) ($_ENV['GVL_LANGUAGES'] ?? ''));

        if ($configured === '') {
            return self::TCF_LANGUAGES;
        }

        $langs = array_filter(array_map(
            static fn (string $l): string => strtolower(trim($l)),
            explode(',', $configured),
        ));

        return array_values($langs);
    }

    // ── Google Additional Consent providers ──────────────────────────

    private function atpRefreshEnabled(): bool
    {
        $flag = strtolower(trim((string) ($_ENV['GVL_UPDATE_ATP'] ?? 'true')));

        return !\in_array($flag, ['false', '0', 'off', 'no'], true);
    }

    /**
     * Refresh google-atp-list.json from Google's published CSV.
     *
     * Columns: provider_id, provider_name, policy_url, domains.
     * Output keeps the legacy shape the bundle expects: {"<id>": {id, name, privacy}}.
     */
    public function refreshGoogleAtpList(): bool
    {
        $url = trim((string) ($_ENV['GVL_ATP_SOURCE_URL'] ?? '')) ?: self::DEFAULT_ATP_SOURCE;
        $csv = $this->http->get($url);

        if ($csv === null) {
            return false;
        }

        $providers = $this->parseAtpCsv($csv);

        if (\count($providers) < self::MIN_ATP_ROWS) {
            $this->logger->warning(
                'Google ATP list rejected: only ' . \count($providers) . ' rows (min ' . self::MIN_ATP_ROWS . ').',
            );

            return false;
        }

        $this->writeAtomic(
            $this->gvlPath . '/' . self::ATP_FILENAME,
            json_encode($providers, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return true;
    }

    /**
     * @return array<string, array{id: int, name: string, privacy: string}>
     */
    private function parseAtpCsv(string $csv): array
    {
        $providers = [];
        $handle    = fopen('php://temp', 'r+');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '');

        // Tolerate the header being absent or reordered.
        $idx = ['id' => 0, 'name' => 1, 'privacy' => 2];
        if (\is_array($header)) {
            foreach ($header as $i => $col) {
                $col = strtolower(trim((string) $col));
                if ($col === 'provider_id') {
                    $idx['id'] = $i;
                } elseif ($col === 'provider_name') {
                    $idx['name'] = $i;
                } elseif ($col === 'policy_url') {
                    $idx['privacy'] = $i;
                }
            }
            // Not actually a header row — rewind and treat it as data.
            if (!isset($header[$idx['id']]) || strtolower(trim((string) $header[$idx['id']])) !== 'provider_id') {
                rewind($handle);
            }
        }

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $id   = trim((string) ($row[$idx['id']] ?? ''));
            $name = trim((string) ($row[$idx['name']] ?? ''));

            if ($id === '' || !ctype_digit($id) || $name === '') {
                continue;
            }

            $providers[$id] = [
                'id'      => (int) $id,
                'name'    => $name,
                'privacy' => trim((string) ($row[$idx['privacy']] ?? '')),
            ];
        }

        fclose($handle);

        return $providers;
    }

    // ── Validation ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     *
     * @throws GvlUpdateException
     */
    private function decodeAndValidate(string $body, int $localVersion, bool $force): array
    {
        // 1. Size floor — catches CDN error pages and truncated responses.
        if (\strlen($body) < self::MIN_BYTES) {
            throw new GvlUpdateException(
                'Vendor list response is only ' . \strlen($body) . ' bytes (min ' . self::MIN_BYTES . ') — looks truncated.',
            );
        }

        // 2. Decodes at all.
        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new GvlUpdateException('Vendor list is not valid JSON: ' . $e->getMessage());
        }

        if (!\is_array($data)) {
            throw new GvlUpdateException('Vendor list did not decode to an object.');
        }

        // 3. Every documented top-level key present.
        foreach (self::REQUIRED_KEYS as $key) {
            if (!isset($data[$key])) {
                throw new GvlUpdateException("Vendor list is missing the required key \"{$key}\".");
            }
        }

        // 4. Spec version pinned — a bump may change the TC string format.
        $spec = (int) $data['gvlSpecificationVersion'];
        if ($spec !== self::EXPECTED_SPEC_VERSION) {
            throw new GvlUpdateException(
                "Refusing update: gvlSpecificationVersion is {$spec}, expected " . self::EXPECTED_SPEC_VERSION
                . '. A specification bump needs a reviewed code change, not an automatic swap.',
            );
        }

        // 5. Policy version pinned — a bump invalidates every stored TC string
        //    and re-prompts the entire user base. Never do that silently.
        $policy = (int) $data['tcfPolicyVersion'];
        if ($policy !== self::EXPECTED_POLICY_VERSION) {
            throw new GvlUpdateException(
                "Refusing update: tcfPolicyVersion is {$policy}, expected " . self::EXPECTED_POLICY_VERSION
                . '. Publishing this would force re-consent for every user — escalate before proceeding.',
            );
        }

        // 6. Version must move forward.
        $remote = $data['vendorListVersion'];
        if (!\is_int($remote) && !ctype_digit((string) $remote)) {
            throw new GvlUpdateException('vendorListVersion is not numeric.');
        }
        $remote = (int) $remote;

        if ($remote < $localVersion && !$force) {
            throw new GvlUpdateException(
                "Refusing update: upstream vendorListVersion {$remote} is older than the local {$localVersion}. Use --force to override.",
            );
        }

        // 7. Vendor count floor.
        if (!\is_array($data['vendors']) || \count($data['vendors']) < self::MIN_VENDORS) {
            throw new GvlUpdateException(
                'Vendor list contains only ' . (\is_array($data['vendors']) ? \count($data['vendors']) : 0)
                . ' vendors (min ' . self::MIN_VENDORS . ').',
            );
        }

        // 8. The taxonomy the preference centre iterates over.
        foreach (range(1, 11) as $purposeId) {
            if (!isset($data['purposes'][$purposeId])) {
                throw new GvlUpdateException("Vendor list is missing purpose {$purposeId}.");
            }
        }
        foreach ([1, 2] as $sfId) {
            if (!isset($data['specialFeatures'][$sfId])) {
                throw new GvlUpdateException("Vendor list is missing special feature {$sfId}.");
            }
        }

        // 9. Fields the consent bundle dereferences without a guard.
        //    iab-script.js:1894 reads e.urls[0].privacy and :1898 reads
        //    e.dataRetention.stdRetention. A single malformed vendor would
        //    throw during render and blank the preference centre on every
        //    site in the fleet, so this is a hard gate rather than a warning.
        foreach ($data['vendors'] as $vendorId => $vendor) {
            if (!\is_array($vendor)) {
                throw new GvlUpdateException("Vendor {$vendorId} is not an object.");
            }
            if (!isset($vendor['urls']) || !\is_array($vendor['urls']) || $vendor['urls'] === []) {
                throw new GvlUpdateException(
                    "Vendor {$vendorId} has no urls[] entry — the preference centre reads urls[0].privacy and would throw.",
                );
            }
            if (!isset($vendor['dataRetention']) || !\is_array($vendor['dataRetention'])) {
                throw new GvlUpdateException(
                    "Vendor {$vendorId} has no dataRetention object — the preference centre reads dataRetention.stdRetention and would throw.",
                );
            }
        }

        return $data;
    }

    // ── Disk I/O ─────────────────────────────────────────────────────

    /**
     * Publish a file without ever exposing a partial read.
     *
     * The temp file must live in the target directory: rename() is only atomic
     * within a single filesystem, and /tmp is a different mount in the
     * containers.
     */
    private function writeAtomic(string $path, string $contents): void
    {
        $dir = \dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new GvlUpdateException("Cannot create directory: {$dir}");
        }

        $tmp = $path . '.tmp.' . getmypid();

        try {
            if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
                throw new GvlUpdateException("Cannot write temp file: {$tmp}");
            }

            @chmod($tmp, 0o644);

            // Windows rename() will not clobber an existing file; the dev host
            // runs the test suite natively, so fall back to unlink-then-rename.
            if (!@rename($tmp, $path)) {
                if (is_file($path) && @unlink($path) && @rename($tmp, $path)) {
                    // Recovered.
                } else {
                    throw new GvlUpdateException("Cannot publish file: {$path}");
                }
            }

            clearstatcache(true, $path);
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Keep historical versions so `archives/vendor-list-v[VERSION].json`
     * lookups resolve (iab-script.js:1203).
     */
    private function archive(int $version, string $json): void
    {
        if ($this->archiveKeep() <= 0) {
            return;
        }

        $this->writeAtomic($this->gvlPath . "/archives/vendor-list-v{$version}.json", $json);
    }

    private function pruneArchives(): void
    {
        $keep = $this->archiveKeep();

        if ($keep <= 0) {
            return;
        }

        $files = glob($this->gvlPath . '/archives/vendor-list-v*.json') ?: [];

        if (\count($files) <= $keep) {
            return;
        }

        // Sort by embedded version number, newest first.
        usort($files, static function (string $a, string $b): int {
            preg_match('/-v(\d+)\.json$/', $a, $ma);
            preg_match('/-v(\d+)\.json$/', $b, $mb);

            return ((int) ($mb[1] ?? 0)) <=> ((int) ($ma[1] ?? 0));
        });

        foreach (\array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }

    private function archiveKeep(): int
    {
        return (int) ($_ENV['GVL_ARCHIVE_KEEP'] ?? 10);
    }

    /**
     * Sidecar metadata so health checks don't decode the whole list.
     *
     * @param array<string, mixed> $data
     */
    private function writeStatus(array $data, int $googleVendorCount): void
    {
        $status = [
            'version'           => (int) $data['vendorListVersion'],
            'lastUpdated'       => (string) $data['lastUpdated'],
            'specVersion'       => (int) $data['gvlSpecificationVersion'],
            'policyVersion'     => (int) $data['tcfPolicyVersion'],
            'hasStandardTexts'  => !empty($data['standardTexts']),
            'vendorCount'       => \count($data['vendors']),
            'googleVendorCount' => $googleVendorCount,
            'fetchedAt'         => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        $this->writeAtomic(
            $this->gvlPath . '/' . self::STATUS_FILENAME,
            json_encode($status, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false || $raw === '') {
            return null;
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning("Malformed JSON on disk, ignoring: {$path}");

            return null;
        }

        return \is_array($data) ? $data : null;
    }
}
