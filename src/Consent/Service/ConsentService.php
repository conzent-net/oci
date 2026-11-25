<?php

declare(strict_types=1);

namespace OCI\Consent\Service;

use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Infrastructure\Ingest\ClientEventLedger;
use OCI\Infrastructure\Ingest\ClientEventLedgerInterface;
use Psr\Log\LoggerInterface;

/**
 * Processes consent actions from the banner script.
 *
 * Handles:
 *  - Status computation (accepted/rejected/partially_accepted) matching legacy logic
 *  - Upsert into oci_consents (update if consent_session exists, insert otherwise)
 *  - Per-category breakdown in oci_consent_categories
 *  - Daily stats aggregation in oci_consent_daily_stats
 */
final class ConsentService
{
    public function __construct(
        private readonly ConsentRepositoryInterface $consentRepo,
        private readonly LoggerInterface $logger,
        private readonly IpPseudonymizer $ipPseudonymizer,
        private readonly ClientEventLedgerInterface $eventLedger,
    ) {}

    /**
     * Process a consent action from the banner script.
     *
     * @param int    $siteId         Site ID
     * @param string $consentSession The conzent_id (40-char session key)
     * @param string $logJson        JSON string of consent choices: ["marketing:yes","analytics:no","conzentConsent:yes"]
     * @param array  $metadata       Additional fields: consented_domain, language, country, consent_time, ip_address, tcf_data, gacm_data, variant_id
     */
    public function processConsent(
        int $siteId,
        string $consentSession,
        string $logJson,
        array $metadata,
    ): void {
        $log = json_decode($logJson, true);
        if (!\is_array($log)) {
            return;
        }

        // Offline-queue replay protection: an event id, once claimed, is
        // never processed again — the client may deliver the same event
        // twice (pagehide flush + queued retry). Old bundles send no id and
        // process exactly as before. A ledger failure never blocks consent
        // recording: the audit trail outranks dedupe.
        $eventId = ClientEventLedger::normalizeEventId($metadata['client_event_id'] ?? null);
        $occurredAt = ClientEventLedger::normalizeOccurredAt($metadata['occurred_at'] ?? null);
        if ($eventId !== null) {
            try {
                if (!$this->eventLedger->claim($siteId, $eventId, 'consent', $occurredAt)) {
                    return; // replay — already recorded, acknowledge silently
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Client event ledger unavailable, processing without dedupe', [
                    'error' => $e->getMessage(),
                    'site_id' => $siteId,
                ]);
            }
        }

        // Parse log to compute status and per-category breakdown
        [$consentStatus, $categories] = $this->computeStatus($log);

        // Build consent record — the IP is pseudonymized before it is ever stored
        $consentData = [
            'site_id' => $siteId,
            'consent_session' => $consentSession,
            'consented_domain' => $metadata['consented_domain'] ?? '',
            'ip_address' => $this->ipPseudonymizer->pseudonymize((string) ($metadata['ip_address'] ?? '')),
            'country' => $metadata['country'] ?? null,
            'consent_status' => $consentStatus,
            'language' => $metadata['language'] ?? null,
            'tcf_data' => $metadata['tcf_data'] ?? null,
            'gacm_data' => $metadata['gacm_data'] ?? null,
            'consent_date' => date('Y-m-d H:i:s'),
            'user_consent_time' => $metadata['consent_time'] ?? null,
            'variant_id' => !empty($metadata['variant_id']) ? (int) $metadata['variant_id'] : null,
            'client_event_id' => $eventId,
            'occurred_at' => $occurredAt,
        ];

        $logEnabled = (bool) ($metadata['log_enabled'] ?? true);

        try {
            if ($logEnabled) {
                // Upsert consent record
                $consentId = $this->consentRepo->upsertConsent($consentSession, $siteId, $consentData);

                // Replace category breakdown
                $this->consentRepo->replaceConsentCategories($consentId, $categories);
            }

            // Daily stats stay on even with per-record logging disabled, so
            // trends keep working without an audit trail being kept. A
            // replayed event counts on the day the visitor acted, not the
            // day the server came back.
            $statDate = $occurredAt !== null ? substr($occurredAt, 0, 10) : null;
            $this->consentRepo->incrementDailyStats($siteId, $consentStatus, $consentData['variant_id'], $statDate);
        } catch (\Throwable $e) {
            $this->logger->error('Consent processing failed', [
                'error' => $e->getMessage(),
                'site_id' => $siteId,
                'consent_session' => $consentSession,
            ]);
        }
    }

    /**
     * Compute overall consent status and per-category breakdown from log entries.
     *
     * Legacy logic (from legacy/app/api/v1/consent.php lines 38-90):
     *   - Parse "category:yes" / "category:no" entries
     *   - Skip "conzentConsent" entry (overall consent flag, not a category)
     *   - All categories accepted → "accepted"
     *   - All categories rejected → "rejected"
     *   - Mixed → "partially_accepted"
     *
     * @return array{0: string, 1: array<string, string>} [status, [slug => 'accepted'|'rejected']]
     */
    private function computeStatus(array $log): array
    {
        $categories = [];
        $accepted = 0;
        $rejected = 0;
        $conzentConsentYes = false;

        foreach ($log as $entry) {
            $parts = explode(':', (string) $entry, 2);
            if (\count($parts) !== 2) {
                continue;
            }

            [$slug, $value] = $parts;

            // Skip the overall consent flag — not a category
            if ($slug === 'conzentConsent') {
                $conzentConsentYes = ($value === 'yes');
                continue;
            }

            // Skip "necessary" — always accepted, not counted
            if ($slug === 'necessary') {
                $categories[$slug] = 'accepted';
                continue;
            }

            if ($value === 'yes') {
                $categories[$slug] = 'accepted';
                $accepted++;
            } else {
                $categories[$slug] = 'rejected';
                $rejected++;
            }
        }

        $totalCategories = $accepted + $rejected;

        if ($totalCategories === 0) {
            // No categories at all — use the conzentConsent flag
            $status = $conzentConsentYes ? 'accepted' : 'rejected';
        } elseif ($rejected === 0) {
            $status = 'accepted';
        } elseif ($accepted === 0) {
            $status = 'rejected';
        } else {
            $status = 'partially_accepted';
        }

        return [$status, $categories];
    }
}
