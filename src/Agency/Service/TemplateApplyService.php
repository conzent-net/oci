<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Agency\Repository\SettingTemplateRepositoryInterface;
use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Banner\Service\ScriptRegenerationJobService;
use OCI\Banner\Service\SettingTemplateService;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Apply one agency template across many client sites.
 *
 * **This is the first cross-tenant write in the product.** Everything else an
 * agency does is a read. Here one action rewrites the consent banners that
 * other people's visitors see, on sites those people own, and if it goes wrong
 * it goes wrong on all of them at once. Four properties follow from that, and
 * none of them is optional:
 *
 * 1. **Authorization resolves the whole batch up front.** One foreign site id
 *    in a list of forty rejects the entire request before anything is written,
 *    via {@see AgencyAccessService::assertCanAccessSites()}. Checking per site
 *    inside the loop would leave a partial apply that had already changed some
 *    clients' banners before it noticed.
 *
 * 2. **Dry run is the default**, for apply and for revert. The caller has to
 *    ask for the write. An agency about to turn IAB on for nine clients gets to
 *    see that first; a preview that can be skipped is a preview nobody looks
 *    at. A revert is a write to a client's live banner too, and used to be the
 *    one write here with no preview.
 *
 * 3. **Every site is its own transaction, and one failure does not abandon the
 *    rest.** A site whose banner row is missing must not stop the other
 *    thirty-nine, and must not leave the failed one half-written.
 *
 * 4. **Every write keeps a revert snapshot and an attributable actor.** An
 *    agency editing a client's compliance settings with no record of who did
 *    it is indistinguishable from a compromise, which is precisely what a DPA
 *    would ask about.
 *
 * ## What a template carries
 *
 * The full banner tuple the A/B testing module already treats as the settings
 * snapshot — `general`, `content`, `layout`, `color` and `layout_key` — plus a
 * whitelist of `oci_sites` columns. So "apply
 * our EU standard" carries the look of the banner as well as its compliance
 * switches. It does not carry `custom_layout_id`: custom layouts are rows
 * owned by ONE site (`oci_custom_layouts.site_id`), so an id captured from
 * Acme's site names nothing on Bob's. A template captured from a site running
 * a custom layout records that layout's base key instead, and says so.
 *
 * Merging, not replacing, throughout — see {@see SettingTemplateService}. A
 * template is a partial map; anything it is silent about survives.
 *
 * ## Which banner
 *
 * A site can hold one banner per framework. Templates read and write the
 * **primary** banner — the GDPR one where there is one — through the ordering
 * {@see BannerRepositoryInterface::getSiteBannerSettings()} now guarantees.
 * Taking "the first row" used to pick whichever of the two the storage engine
 * returned first.
 *
 * ## Drift is measured on governed keys only
 *
 * The fingerprint covers the key paths the template names, extracted from the
 * site's current settings — see {@see SettingTemplateService::governed()}.
 * Hashing the entire settings blob reported drift whenever a client changed
 * anything at all, including settings no template has an opinion about.
 *
 * ## Regeneration is queued
 *
 * Saving is immediate; the served `script.js` is rewritten by the background
 * worker seconds later. Bulk applies go at normal priority, reverts at high —
 * somebody is waiting on a revert, and nobody is refreshing forty client sites
 * at once to watch a template land.
 *
 * **On test coverage, honestly.** The merge, diff and governed-subset rules
 * this depends on are unit tested in `SettingTemplateServiceTest`. This
 * orchestrator is not: it needs a Connection, three repositories and a
 * Redis-backed job queue, and Predis exposes its commands through `__call`,
 * which PHPUnit cannot double without a wrapper interface. It is exercised end
 * to end against the restored production dataset instead. That is a weaker
 * guarantee than a test suite, because nothing re-runs it.
 */
final class TemplateApplyService
{
    /**
     * Layout keys are `<framework>/<name>` and are used to build a file path in
     * {@see \OCI\Banner\Service\LayoutService::getLayoutHtml()}. Anything else
     * is refused before it is written, however it got into a payload.
     */
    private const LAYOUT_KEY_PATTERN = '/^[a-z0-9_-]+\/[a-z0-9_-]+$/';

    public function __construct(
        private readonly Connection $db,
        private readonly SettingTemplateRepositoryInterface $templates,
        private readonly SettingTemplateService $merger,
        private readonly BannerRepositoryInterface $banners,
        private readonly SiteRepositoryInterface $sites,
        private readonly ScriptRegenerationJobService $jobs,
        private readonly AgencyAccessService $access,
        private readonly AgencyHealthCache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Preview or perform an application.
     *
     * @param array<int, int> $siteIds
     *
     * @return array{
     *     ok: bool,
     *     dry_run: bool,
     *     template: string,
     *     sites: array<int, array{site_id: int, domain: string, status: string, changes: array<string, array{from: mixed, to: mixed}>, notes: array<int, string>, error: ?string}>,
     *     changed: int,
     *     unchanged: int,
     *     failed: int,
     *     queued: int
     * }
     *
     * @throws \OCI\Agency\Exception\AgencyAccessDenied if any site is out of scope
     * @throws \DomainException if the template is not this agency's to use
     */
    public function apply(AgencyScope $scope, int $templateId, array $siteIds, bool $dryRun = true, int $actorId = 0): array
    {
        $template = $this->templates->findForAgency($templateId, $scope->agencyId());

        if ($template === null) {
            throw new \DomainException('That template does not exist, or does not belong to this agency.');
        }

        // Whole batch, or nothing. Throws before a single write.
        $verified = $this->access->assertCanAccessSites($scope, $siteIds);

        $payload = $this->decodePayload((string) $template['payload']);

        $results = [];
        $changed = 0;
        $unchanged = 0;
        $failed = 0;
        $toRegenerate = [];

        // What the site will report as its applied template. System templates
        // use their key so the existing labels ("Google Consent Mode Basic +
        // TCF") still resolve; an agency's own template uses its name.
        $templateKey = mb_substr(
            (string) ($template['system_key'] ?? '') !== ''
                ? (string) $template['system_key']
                : (string) $template['name'],
            0,
            60,
        );

        foreach ($verified as $siteId) {
            $outcome = $this->applyToSite($siteId, $templateId, $templateKey, $payload, $dryRun, $actorId);
            $results[] = $outcome;

            match ($outcome['status']) {
                'changed' => $changed++,
                'unchanged' => $unchanged++,
                default => $failed++,
            };

            if (!$dryRun && $outcome['status'] === 'changed') {
                $toRegenerate[] = $siteId;
            }
        }

        $queued = 0;

        if (!$dryRun) {
            $queued = $this->jobs->enqueue(
                $toRegenerate,
                ScriptRegenerationJobService::PRIORITY_NORMAL,
                'template "' . $templateKey . '" applied by agency ' . $scope->agencyId(),
            );
            $this->cache->forget($scope->agencyId());
        }

        return [
            'ok' => $failed === 0,
            'dry_run' => $dryRun,
            'template' => (string) $template['name'],
            'sites' => $results,
            'changed' => $changed,
            'unchanged' => $unchanged,
            'failed' => $failed,
            'queued' => $queued,
        ];
    }

    /**
     * Build a template payload from a site the agency already manages.
     *
     * The primary way an agency creates a template: "save what we did for Acme
     * as our standard". Far better than a blank form, because the settings they
     * want are already correct on a real site and retyping them is where
     * mistakes come from.
     *
     * Per-language banner text is left out — roughly 65 fields per language,
     * in the *client's* language, and copying it would overwrite every other
     * client's copy with this one's. Per-site identity (logos, policy URLs) is
     * stripped by {@see SettingTemplateService::PROTECTED_CONTENT_KEYS} on the
     * way back out, but it is dropped here too so it never reaches storage: a
     * template sitting in the database holding a client's logo path is a leak
     * waiting for the next feature to find it.
     *
     * @return array{payload: array<string, mixed>, warnings: array<int, string>}
     *
     * @throws \OCI\Agency\Exception\AgencyAccessDenied
     * @throws \DomainException if the site has no banner to capture
     */
    public function captureFromSite(AgencyScope $scope, int $siteId): array
    {
        $site = $this->access->assertCanAccessSite($scope, $siteId);
        $banner = $this->primaryBanner($siteId);

        if ($banner === null) {
            throw new \DomainException('That site has no banner configured yet, so there is nothing to capture.');
        }

        $content = $this->decodeJson((string) ($banner['content_setting'] ?? ''));

        foreach (SettingTemplateService::PROTECTED_CONTENT_KEYS as $key) {
            unset($content[$key]);
        }

        // The nested per-framework blocks carry the same identity keys one
        // level down, where a top-level unset does not reach them.
        foreach (['gdpr', 'ccpa'] as $framework) {
            foreach (['cookie_notice', 'preference_center', 'revisit_consent_button'] as $section) {
                foreach (SettingTemplateService::PROTECTED_CONTENT_KEYS as $key) {
                    unset($content[$framework][$section][$key]);
                }
            }
        }

        $warnings = [];
        $layoutKey = trim((string) ($banner['layout_key'] ?? ''));
        $customLayoutId = (int) ($banner['custom_layout_id'] ?? 0);

        if ($customLayoutId > 0) {
            // A custom layout is HTML owned by this one site. The template can
            // carry the system layout it was based on, not the edits.
            $base = (string) $this->db->fetchOne(
                'SELECT base_layout_key FROM oci_custom_layouts WHERE id = :id AND site_id = :siteId',
                ['id' => $customLayoutId, 'siteId' => $siteId],
                ['id' => ParameterType::INTEGER, 'siteId' => ParameterType::INTEGER],
            );

            if ($base !== '') {
                $layoutKey = $base;
            }

            $warnings[] = 'This site runs a custom-edited layout. Templates cannot carry edited layout HTML, so the '
                . 'template records the layout it was based on (' . ($layoutKey !== '' ? $layoutKey : 'default')
                . ') and sites it is applied to will use that.';
        }

        if ($layoutKey !== '' && preg_match(self::LAYOUT_KEY_PATTERN, $layoutKey) !== 1) {
            $warnings[] = 'The layout key on this site (' . $layoutKey . ') is not in a form a template can carry, so the layout was left out.';
            $layoutKey = '';
        }

        return [
            'payload' => [
                'general' => $this->decodeJson((string) ($banner['general_setting'] ?? '')),
                'content' => $content,
                'layout' => $this->decodeJson((string) ($banner['layout_setting'] ?? '')),
                'color' => $this->decodeJson((string) ($banner['color_setting'] ?? '')),
                'layout_key' => $layoutKey !== '' ? $layoutKey : null,
                'site' => $this->siteSubset($site, ['gcm_enabled', 'tag_fire_enabled', 'block_iframe', 'banner_delay_ms']),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * Preview or perform putting a site back the way it was before its last
     * template application.
     *
     * The snapshot is the settings as they were immediately before the write,
     * not "the site's original state" — reverting twice does not walk further
     * back, which is a deliberate limit rather than an oversight: a chain of
     * undos across other people's sites is a much larger feature than it looks.
     * A completed revert removes the site's template link, so the second
     * attempt reports there is nothing to undo rather than silently rewriting
     * the same snapshot again.
     *
     * The banner the snapshot belongs to is checked before anything is written.
     * A revert whose banner row had gone would previously write the site
     * columns, report success, and leave the banner exactly as it was.
     *
     * @return array{ok: bool, dry_run: bool, site_id: int, domain: string, changes: array<string, array{from: mixed, to: mixed}>, error: ?string}
     *
     * @throws \OCI\Agency\Exception\AgencyAccessDenied
     */
    public function revert(AgencyScope $scope, int $siteId, int $actorId = 0, bool $dryRun = true): array
    {
        $site = $this->access->assertCanAccessSite($scope, $siteId);
        $domain = (string) ($site['domain'] ?? ('site ' . $siteId));
        $fail = static fn (string $error): array => [
            'ok' => false,
            'dry_run' => $dryRun,
            'site_id' => $siteId,
            'domain' => $domain,
            'changes' => [],
            'error' => $error,
        ];

        $link = $this->templates->findLink($siteId);

        if ($link === null || ($link['previous_settings'] ?? '') === '') {
            return $fail('There is nothing to undo for this site — no template has been applied to it, or the snapshot from that application is no longer available.');
        }

        /** @var array<string, mixed> $previous */
        $previous = json_decode((string) $link['previous_settings'], true) ?: [];
        $bannerId = (int) ($previous['banner_id'] ?? 0);

        if ($bannerId <= 0) {
            return $fail('The snapshot for this site does not say which banner it belongs to, so it cannot be restored safely.');
        }

        $banner = null;

        foreach ($this->banners->getSiteBannerSettings($siteId) as $candidate) {
            if ((int) $candidate['id'] === $bannerId) {
                $banner = $candidate;
                break;
            }
        }

        if ($banner === null) {
            return $fail('The banner this template was applied to no longer exists on the site, so there is nothing to restore it onto.');
        }

        // What the revert would undo: every difference between the site now
        // and the snapshot, section by section, in BOTH directions. The
        // snapshot is a full copy rather than a template-shaped partial, so a
        // key added since — by the template or by the client — is removed by
        // the revert and has to be shown as such.
        $snapshotSections = [
            'general' => $this->decodeJson((string) ($banner['general_setting'] ?? '')),
            'content' => $this->decodeJson((string) ($banner['content_setting'] ?? '')),
            'layout' => $this->decodeJson((string) ($banner['layout_setting'] ?? '')),
            'color' => $this->decodeJson((string) ($banner['color_setting'] ?? '')),
        ];

        $changes = [];

        foreach ($snapshotSections as $section => $current) {
            if (\array_key_exists($section, $previous) && \is_array($previous[$section])) {
                $changes += $this->merger->diffFull($current, $previous[$section], $section);
            }
        }

        foreach (['layout_key', 'custom_layout_id'] as $scalar) {
            if (\array_key_exists($scalar, $previous) && ($banner[$scalar] ?? null) != $previous[$scalar]) {
                $changes[$scalar] = ['from' => $banner[$scalar] ?? null, 'to' => $previous[$scalar]];
            }
        }

        $previousSite = \is_array($previous['site'] ?? null) ? $previous['site'] : [];
        $changes += $this->merger->diff($this->siteSubset($site, array_keys($previousSite)), $previousSite, 'site');

        if ($dryRun) {
            return ['ok' => true, 'dry_run' => true, 'site_id' => $siteId, 'domain' => $domain, 'changes' => $changes, 'error' => null];
        }

        $bannerUpdate = [
            'general_setting' => json_encode($previous['general'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'content_setting' => json_encode($previous['content'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];

        // Older snapshots predate layout and colour capture. A snapshot that
        // does not carry a section must not blank it.
        foreach (['layout' => 'layout_setting', 'color' => 'color_setting'] as $section => $column) {
            if (\array_key_exists($section, $previous) && \is_array($previous[$section])) {
                $bannerUpdate[$column] = json_encode($previous[$section], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }

        if (\array_key_exists('layout_key', $previous)) {
            $bannerUpdate['layout_key'] = $previous['layout_key'] !== null ? (string) $previous['layout_key'] : null;
        }

        if (\array_key_exists('custom_layout_id', $previous)) {
            $bannerUpdate['custom_layout_id'] = $previous['custom_layout_id'] !== null ? (int) $previous['custom_layout_id'] : null;
        }

        $this->db->beginTransaction();

        try {
            $this->banners->updateBannerSetting($bannerId, $bannerUpdate);

            if ($previousSite !== []) {
                $this->sites->updateSiteSettings($siteId, $previousSite);
            }

            $this->templates->deleteLink($siteId);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error("Template revert failed for site {$siteId}: " . $e->getMessage());

            return $fail('Could not restore the settings for this site.');
        }

        // High priority: a person is waiting on this one.
        $this->jobs->enqueue([$siteId], ScriptRegenerationJobService::PRIORITY_HIGH, 'template reverted on site ' . $siteId);
        $this->cache->forget($scope->agencyId());

        return ['ok' => true, 'dry_run' => false, 'site_id' => $siteId, 'domain' => $domain, 'changes' => $changes, 'error' => null];
    }

    /**
     * Sites on an agency's templates whose governed settings no longer match.
     *
     * A link whose template has since been deleted cannot be checked — there
     * is no longer a payload to say which keys were governed — and is skipped.
     *
     * @param array<int, int> $siteIds
     *
     * @return array<int, array{site_id: int, domain: string, template_id: int, template_name: ?string, applied_at: mixed}>
     */
    public function drift(AgencyScope $scope, array $siteIds): array
    {
        $verified = $this->access->assertCanAccessSites($scope, $siteIds);
        $links = $this->templates->findLinks($verified);
        $drifted = [];

        foreach ($verified as $siteId) {
            $link = $links[$siteId] ?? null;

            if ($link === null || $link['template_id'] === null || ($link['template_payload'] ?? '') === '') {
                continue;
            }

            $payload = $this->decodePayload((string) $link['template_payload']);
            $current = $this->currentFingerprint($siteId, $payload);

            if ($current !== null && $current !== (string) $link['applied_fingerprint']) {
                // The domain and client come along so the caller can NAME the
                // drifted sites. Reporting only a count leaves the agency to
                // guess which of forty clients to look at, which is the one
                // thing this screen exists to answer.
                $site = $this->sites->findById($siteId) ?? [];

                $drifted[] = [
                    'site_id' => $siteId,
                    'domain' => (string) ($site['domain'] ?? ('site ' . $siteId)),
                    'template_id' => (int) $link['template_id'],
                    'template_name' => $link['template_name'] !== null ? (string) $link['template_name'] : null,
                    'applied_at' => $link['applied_at'] ?? null,
                ];
            }
        }

        return $drifted;
    }

    /**
     * @param array{general: array<string,mixed>, content: array<string,mixed>, layout: array<string,mixed>, color: array<string,mixed>, layout_key: ?string, site: array<string,mixed>} $payload
     *
     * @return array{site_id: int, domain: string, status: string, changes: array<string, array{from: mixed, to: mixed}>, notes: array<int, string>, error: ?string}
     */
    private function applyToSite(
        int $siteId,
        int $templateId,
        string $templateKey,
        array $payload,
        bool $dryRun,
        int $actorId,
    ): array {
        $site = $this->sites->findById($siteId);
        $domain = (string) ($site['domain'] ?? ('site ' . $siteId));
        $notes = [];

        $banner = $this->primaryBanner($siteId);

        if ($banner === null) {
            return [
                'site_id' => $siteId,
                'domain' => $domain,
                'status' => 'failed',
                'changes' => [],
                'notes' => [],
                'error' => 'No banner is configured for this site yet.',
            ];
        }

        $bannerId = (int) $banner['id'];

        $existing = [
            'general' => $this->decodeJson((string) ($banner['general_setting'] ?? '')),
            'content' => $this->decodeJson((string) ($banner['content_setting'] ?? '')),
            'layout' => $this->decodeJson((string) ($banner['layout_setting'] ?? '')),
            'color' => $this->decodeJson((string) ($banner['color_setting'] ?? '')),
        ];
        $existingLayoutKey = (string) ($banner['layout_key'] ?? '');
        $existingCustomLayoutId = $banner['custom_layout_id'] !== null ? (int) $banner['custom_layout_id'] : null;

        // `template_applied` is part of what an apply changes, even though it
        // is not in the template payload. Without it the settings landed but
        // the site never said which template it was on — so the customer's own
        // dashboard, which reads exactly this column, showed the previous
        // template and the whole apply looked like it had done nothing.
        $siteChanges = $payload['site'] + ['template_applied' => $templateKey];
        $existingSite = $this->siteSubset($site ?? [], array_keys($siteChanges));

        $new = [
            'general' => $this->merger->mergeSection($existing['general'], $payload['general']),
            'content' => $this->merger->mergeContent($existing['content'], $payload['content']),
            'layout' => $this->merger->mergeSection($existing['layout'], $payload['layout']),
            'color' => $this->merger->mergeSection($existing['color'], $payload['color']),
        ];
        $newSite = array_replace($existingSite, $siteChanges);

        $changes = $this->merger->diff($existing['general'], $payload['general'], 'general')
            + $this->merger->diff($existing['content'], $payload['content'], 'content')
            + $this->merger->diff($existing['layout'], $payload['layout'], 'layout')
            + $this->merger->diff($existing['color'], $payload['color'], 'color')
            + $this->merger->diff($existingSite, $siteChanges, 'site');

        // The layout. Framework-specific — a gdpr/ key must not land on a CCPA
        // banner — and it supersedes a custom layout, or the template's layout
        // would be saved and never shown.
        $applyLayoutKey = false;
        $layoutKey = $payload['layout_key'];

        if ($layoutKey !== null) {
            $existingFramework = $existingLayoutKey !== '' ? strtok($existingLayoutKey, '/') : null;
            $templateFramework = strtok($layoutKey, '/');

            if ($existingFramework !== null && $existingFramework !== $templateFramework) {
                $notes[] = sprintf(
                    'Layout not applied: this banner is %s and the template\'s layout is %s.',
                    $existingFramework,
                    $layoutKey,
                );
            } else {
                $applyLayoutKey = true;

                if ($existingLayoutKey !== $layoutKey) {
                    $changes['layout_key'] = ['from' => $existingLayoutKey !== '' ? $existingLayoutKey : null, 'to' => $layoutKey];
                }

                if ($existingCustomLayoutId !== null) {
                    $changes['custom_layout_id'] = ['from' => $existingCustomLayoutId, 'to' => null];
                    $notes[] = 'This site\'s custom-edited layout is replaced by the template\'s layout. Revert restores it.';
                }
            }
        }

        if ($dryRun) {
            return [
                'site_id' => $siteId,
                'domain' => $domain,
                'status' => $changes === [] ? 'unchanged' : 'changed',
                'changes' => $changes,
                'notes' => $notes,
                'error' => null,
            ];
        }

        $snapshot = [
            'banner_id' => $bannerId,
            'general' => $existing['general'],
            'content' => $existing['content'],
            'layout' => $existing['layout'],
            'color' => $existing['color'],
            'layout_key' => $existingLayoutKey !== '' ? $existingLayoutKey : null,
            'custom_layout_id' => $existingCustomLayoutId,
            'site' => $existingSite,
        ];

        // A site that already matches is still ON the template, and has to be
        // recorded as such. Skipping the link here looked like a harmless
        // optimisation and was not: an agency applying to forty sites where
        // four already matched would have tracked thirty-six, and those four
        // could never report drift or be reverted — they would sit outside the
        // standard while appearing to be inside it. Record the link, skip only
        // the settings write and the regeneration, which genuinely are no-ops.
        if ($changes === []) {
            $this->templates->recordApplication(
                $siteId,
                $templateId,
                $this->currentFingerprint($siteId, $payload) ?? '',
                $snapshot,
                $actorId,
            );

            return ['site_id' => $siteId, 'domain' => $domain, 'status' => 'unchanged', 'changes' => [], 'notes' => $notes, 'error' => null];
        }

        $bannerUpdate = [
            'general_setting' => json_encode($new['general'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'content_setting' => json_encode($new['content'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];

        // Only sections the template actually carries: a template saved before
        // layout and colour were captured must not blank them.
        if ($payload['layout'] !== []) {
            $bannerUpdate['layout_setting'] = json_encode($new['layout'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        if ($payload['color'] !== []) {
            $bannerUpdate['color_setting'] = json_encode($new['color'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        if ($applyLayoutKey) {
            $bannerUpdate['layout_key'] = $layoutKey;
            $bannerUpdate['custom_layout_id'] = null;
        }

        // Each site in its own transaction: a failure here must not leave this
        // site half-written, and must not abandon the sites after it.
        $this->db->beginTransaction();

        try {
            $this->banners->updateBannerSetting($bannerId, $bannerUpdate);

            if ($newSite !== []) {
                $this->sites->updateSiteSettings($siteId, $newSite);
            }

            $this->templates->recordApplication(
                $siteId,
                $templateId,
                // Read back through the SAME path the drift check uses, so the
                // stored fingerprint and any later comparison are derived
                // identically by construction rather than by two functions
                // that have to be kept in agreement by hand.
                $this->currentFingerprint($siteId, $payload) ?? '',
                $snapshot,
                $actorId,
            );

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error("Template apply failed for site {$siteId}: " . $e->getMessage());

            return [
                'site_id' => $siteId,
                'domain' => $domain,
                'status' => 'failed',
                'changes' => $changes,
                'notes' => $notes,
                'error' => 'Could not save the settings for this site.',
            ];
        }

        return ['site_id' => $siteId, 'domain' => $domain, 'status' => 'changed', 'changes' => $changes, 'notes' => $notes, 'error' => null];
    }

    /**
     * The GDPR banner where the site has one, otherwise its first banner.
     *
     * @return array<string, mixed>|null
     */
    private function primaryBanner(int $siteId): ?array
    {
        $banners = $this->banners->getSiteBannerSettings($siteId, 'gdpr');

        return $banners === [] ? null : $banners[0];
    }

    /**
     * The site's current values for the keys this template governs, read from
     * the database.
     *
     * **Both the stored fingerprint and the drift check go through here**, and
     * that is the entire point. They used to be derived differently: the stored
     * one was hashed from the in-memory values just written, while the drift
     * check hashed what came back out of MariaDB over a different key list. The
     * two could never agree, so every site reported drift the instant it was
     * applied to.
     *
     * @param array{general: array<string,mixed>, content: array<string,mixed>, layout: array<string,mixed>, color: array<string,mixed>, layout_key: ?string, site: array<string,mixed>} $payload
     *
     * @return array<string, mixed>|null null when there is no banner
     */
    private function governedState(int $siteId, array $payload): ?array
    {
        $banner = $this->primaryBanner($siteId);

        if ($banner === null) {
            return null;
        }

        $site = $this->sites->findById($siteId) ?? [];
        $state = [];

        foreach (['general' => 'general_setting', 'content' => 'content_setting', 'layout' => 'layout_setting', 'color' => 'color_setting'] as $section => $column) {
            if ($payload[$section] !== []) {
                $state[$section] = $this->merger->governed($this->decodeJson((string) ($banner[$column] ?? '')), $payload[$section]);
            }
        }

        if ($payload['layout_key'] !== null) {
            $state['layout_key'] = (string) ($banner['layout_key'] ?? '');
        }

        // template_applied is written by every apply, so a client switching
        // template from their own dashboard is a governed change too.
        $state['site'] = $this->siteSubset($site, array_merge(array_keys($payload['site']), ['template_applied']));

        return $state;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function fingerprintOf(array $state): string
    {
        $this->normalise($state);
        $this->ksortRecursive($state);

        return md5((string) json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * Flatten scalar types before hashing.
     *
     * These settings round-trip through JSON and a legacy schema, so the same
     * value arrives as 1, "1" or true depending on the path it took. Hashing
     * the raw values makes a storage detail look like somebody edited the site.
     *
     * @param array<mixed> $data
     */
    private function normalise(array &$data): void
    {
        foreach ($data as &$value) {
            if (\is_array($value)) {
                $this->normalise($value);
                continue;
            }

            $value = match (true) {
                \is_bool($value) => $value ? '1' : '0',
                $value === null => '',
                default => (string) $value,
            };
        }
    }

    /**
     * The fingerprint of a site's governed settings right now.
     *
     * @param array{general: array<string,mixed>, content: array<string,mixed>, layout: array<string,mixed>, color: array<string,mixed>, layout_key: ?string, site: array<string,mixed>} $payload
     */
    private function currentFingerprint(int $siteId, array $payload): ?string
    {
        $state = $this->governedState($siteId, $payload);

        return $state === null ? null : $this->fingerprintOf($state);
    }

    /**
     * @param array<string, mixed> $site
     * @param array<int, string>   $keys
     *
     * @return array<string, mixed>
     */
    private function siteSubset(array $site, array $keys): array
    {
        $subset = [];

        foreach ($keys as $key) {
            if (\array_key_exists($key, $site)) {
                $subset[$key] = $site[$key];
            }
        }

        return $subset;
    }

    /**
     * Decode a stored payload into its six sections, tolerating older
     * templates that only carried three.
     *
     * The content section is stripped of protected keys HERE as well as on
     * merge, so that the governed-key fingerprint never includes a per-site
     * identity value even if one reached storage.
     *
     * @return array{general: array<string,mixed>, content: array<string,mixed>, layout: array<string,mixed>, color: array<string,mixed>, layout_key: ?string, site: array<string,mixed>}
     */
    private function decodePayload(string $json): array
    {
        $decoded = json_decode($json, true);
        $decoded = \is_array($decoded) ? $decoded : [];

        $section = static fn (string $key): array => \is_array($decoded[$key] ?? null) ? $decoded[$key] : [];

        $content = $section('content');

        foreach (SettingTemplateService::PROTECTED_CONTENT_KEYS as $protected) {
            unset($content[$protected]);
        }

        $layoutKey = trim((string) ($decoded['layout_key'] ?? ''));

        return [
            'general' => $section('general'),
            'content' => $content,
            'layout' => $section('layout'),
            'color' => $section('color'),
            'layout_key' => $layoutKey !== '' && preg_match(self::LAYOUT_KEY_PATTERN, $layoutKey) === 1 ? $layoutKey : null,
            'site' => $section('site'),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Key order must not change the fingerprint, or every apply would look like
     * drift the moment PHP happened to serialise a map differently.
     *
     * @param array<string, mixed> $array
     */
    private function ksortRecursive(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (\is_array($value)) {
                $this->ksortRecursive($value);
            }
        }
    }
}
