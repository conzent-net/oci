<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Banner\Service\ScriptRegenerationJobService;
use Psr\Log\LoggerInterface;

/**
 * Push agency-driven branding changes out to the files visitors actually load.
 *
 * **The database is not the access boundary; `script.js` is.** The generated
 * bundle is a static file written once per regeneration and served from disk.
 * So every transition that changes whether Conzent branding belongs on a banner
 * has to rewrite those files, or the displayed state and the entitled state
 * quietly diverge — a suspended agency's clients keep their unbranded banners
 * indefinitely, and nothing in the product ever notices.
 *
 * This is the same class of failure as the backup archives that outlived the
 * volume they were supposed to live on: a row said one thing, the filesystem
 * said another, and only the filesystem was load-bearing.
 *
 * Four transitions need it, and the fourth is the one that is easy to miss:
 *
 * - **approve** — an agency's book becomes entitled.
 * - **suspend** — it stops being entitled. Branding must come back.
 * - **reinstate** — it starts again.
 * - **linking or unlinking a client** — because the *first* client is what
 *   turns branding off on the agency's **own** sites, and removing the *last*
 *   one turns it back on. So a link change has to regenerate the whole book,
 *   not just the site being linked.
 *
 * ## Queued, at high priority
 *
 * This used to regenerate inline and claimed that was fine at 21 sites. It was
 * not measured: regeneration is 2.8 seconds per site on this stack, so an
 * agency accepting an invite with twenty client sites sat on a spinner for a
 * minute. Every call here now queues through
 * {@see ScriptRegenerationJobService} at **high** priority — these are the
 * transitions where a visitor is currently seeing something the site is no
 * longer entitled to, so they go ahead of bulk template work and never wait
 * behind a running scan.
 *
 * Every call also clears the agency's cached compliance overview, because each
 * of these transitions changes which sites are in the book or what they are
 * entitled to, and the overview was otherwise stale for five minutes.
 */
final class AgencyScriptSync
{
    public function __construct(
        private readonly Connection $db,
        private readonly ScriptRegenerationJobService $jobs,
        private readonly AgencyHealthCache $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queue regeneration for every site whose branding depends on this
     * agency's state: the agency's own sites plus those of all its currently
     * linked clients.
     *
     * @param array<int, int> $alsoUserIds accounts to include even though they
     *                                     are no longer linked. **Unlink must
     *                                     pass the departing client here.** By
     *                                     the time this runs, `date_to` is set
     *                                     and the ex-client no longer matches
     *                                     the join — so without this they would
     *                                     keep serving agency-granted branding
     *                                     indefinitely, which is precisely the
     *                                     failure this class exists to prevent.
     *
     * @return int sites queued
     */
    public function regenerateForAgencyUser(int $agencyUserId, array $alsoUserIds = [], string $reason = ''): int
    {
        $this->cache->forget($this->agencyRowId($agencyUserId));

        $siteIds = $this->affectedSiteIds($agencyUserId, $alsoUserIds);

        if ($siteIds === []) {
            return 0;
        }

        $queued = $this->jobs->enqueue(
            $siteIds,
            ScriptRegenerationJobService::PRIORITY_HIGH,
            $reason !== '' ? $reason : sprintf('agency user %d changed', $agencyUserId),
        );

        $this->logger->info(sprintf(
            'Agency user %d: %d of %d affected site(s) queued for regeneration.',
            $agencyUserId,
            $queued,
            \count($siteIds),
        ));

        return $queued;
    }

    /**
     * The agency's own sites plus every active client's sites.
     *
     * Deliberately ignores `oci_agencies.status`: a suspension has to be able
     * to regenerate the very book it is revoking, so this asks "whose banners
     * depend on this agency" rather than "what may this agency see".
     *
     * @param array<int, int> $alsoUserIds
     *
     * @return array<int, int>
     */
    private function affectedSiteIds(int $agencyUserId, array $alsoUserIds = []): array
    {
        $extra = array_values(array_filter(array_map('intval', $alsoUserIds), static fn (int $id): bool => $id > 0));

        // A parameterised list of ids the caller supplied. Passing an empty
        // array to an IN () is a syntax error, so fall back to a value no
        // user id can be rather than branching the SQL.
        $params = ['userId' => $agencyUserId, 'extra' => $extra === [] ? [0] : $extra];

        /** @var array<int, int|string> $ids */
        $ids = $this->db->fetchFirstColumn(
            'SELECT DISTINCT s.id
             FROM oci_sites AS s
             WHERE s.deleted_at IS NULL
               AND (
                    s.user_id = :userId
                 OR s.user_id IN (:extra)
                 OR s.user_id IN (
                        SELECT ac.customer_user_id
                        FROM oci_agency_customers AS ac
                        INNER JOIN oci_agencies AS a ON a.id = ac.agency_id
                        WHERE a.user_id = :userId
                          AND ac.date_to IS NULL
                    )
               )',
            $params,
            ['userId' => ParameterType::INTEGER, 'extra' => ArrayParameterType::INTEGER],
        );

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    /**
     * `oci_agencies.id` for this user, or 0. Status is ignored on purpose: the
     * cache for a suspended agency is exactly the one that must be cleared.
     */
    private function agencyRowId(int $agencyUserId): int
    {
        return (int) $this->db->fetchOne(
            'SELECT id FROM oci_agencies WHERE user_id = :userId LIMIT 1',
            ['userId' => $agencyUserId],
            ['userId' => ParameterType::INTEGER],
        );
    }
}
