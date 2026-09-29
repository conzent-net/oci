<?php

declare(strict_types=1);

namespace OCI\Agency\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use OCI\Agency\Repository\AgencyApplicationRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * The partnership request lifecycle: apply, review, approve, reject, suspend.
 *
 * Becoming an agency is not self-serve. An account applies, a human decides,
 * and only an approval flips `oci_agencies.status` to `approved` — which is the
 * single fact {@see AgencyScope} checks before any cross-account read is
 * possible. Free unconditional signup plus free white-label was a pricing hole
 * (create a second account, flag it an agency, invite your own main account as
 * a client, take `custom_branding` for nothing); a human at the door closes it.
 *
 * What did not change: an agency is still never required to run Conzent on its
 * own website. The door is reviewed, the price of entry did not go up.
 *
 * **Applying creates the agency row immediately, as `pending`.** That looks odd
 * until you need the status page: the applicant has to be told where their
 * request stands, and one row that carries its own state is simpler than two
 * places that have to agree. Nothing is granted by the row existing — the scope
 * refuses every status except `approved`, so a pending row is inert.
 *
 * **Approve, suspend and reinstate all rewrite `script.js`.** Branding
 * entitlement is derived from agency state, and the generated bundle is a
 * static file — so a decision that is not followed by regeneration leaves a
 * suspended agency's clients serving unbranded banners forever. See
 * {@see AgencyScriptSync}, and note it runs *after* the transaction commits:
 * regenerating inside it would hold table locks open across filesystem writes,
 * and a rollback after files were rewritten would leave the disk ahead of the
 * database.
 */
final class AgencyApplicationService
{
    private const CLIENT_COUNT_BANDS = ['1-5', '6-20', '21-50', '50+'];

    public function __construct(
        private readonly Connection $db,
        private readonly AgencyApplicationRepositoryInterface $applications,
        private readonly AgencyScriptSync $scriptSync,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Submit a partnership request.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, errors: array<string, string>, application_id: ?int}
     */
    public function apply(int $userId, string $userEmail, array $input): array
    {
        $errors = $this->validate($input);

        if ($this->applications->findPendingForUser($userId) !== null) {
            $errors['form'] = 'You already have a partnership request under review.';
        }

        $existing = $this->agencyRow($userId);

        if ($existing !== null && $existing['status'] === 'approved') {
            $errors['form'] = 'This account is already an approved agency.';
        }

        if ($existing !== null && $existing['status'] === 'suspended') {
            // Re-applying is not the route back from a suspension. Letting it
            // be one would turn a revocation into a speed bump.
            $errors['form'] = 'This agency account is suspended. Reply to the suspension notice to discuss reinstatement.';
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'application_id' => null];
        }

        $companyName = trim((string) $input['company_name']);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->db->beginTransaction();

        try {
            $applicationId = $this->applications->create([
                'user_id' => $userId,
                'company_name' => $companyName,
                'website' => $this->normaliseUrl((string) ($input['website'] ?? '')),
                'contact_email' => trim((string) ($input['contact_email'] ?? '')) ?: $userEmail,
                'client_count' => \in_array($input['client_count'] ?? '', self::CLIENT_COUNT_BANDS, true)
                    ? (string) $input['client_count']
                    : null,
                'pitch' => trim((string) ($input['pitch'] ?? '')) ?: null,
            ]);

            if ($existing === null) {
                $this->db->insert('oci_agencies', [
                    'user_id' => $userId,
                    'name' => $companyName,
                    'contact_email' => trim((string) ($input['contact_email'] ?? '')) ?: $userEmail,
                    'agency_type' => 'agency',
                    'is_active' => 1,
                    'status' => 'pending',
                    'applied_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                // Re-application after a rejection. Clear the previous decision
                // so a stale rejection note is never shown next to a live
                // pending request.
                $this->db->update('oci_agencies', [
                    'name' => $companyName,
                    'status' => 'pending',
                    'applied_at' => $now,
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                    'review_note' => null,
                    'updated_at' => $now,
                ], ['id' => (int) $existing['id']]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Agency application failed: ' . $e->getMessage());

            return [
                'ok' => false,
                'errors' => ['form' => 'Could not submit the request. Please try again.'],
                'application_id' => null,
            ];
        }

        return ['ok' => true, 'errors' => [], 'application_id' => $applicationId];
    }

    /**
     * Approve a request. The only path to `status = 'approved'`.
     *
     * @return array{ok: bool, error: ?string, user_id: ?int, email: ?string}
     */
    public function approve(int $applicationId, int $reviewerId, ?string $note = null): array
    {
        $application = $this->applications->findById($applicationId);

        if ($application === null || $application['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'That request is not awaiting a decision.', 'user_id' => null, 'email' => null];
        }

        $userId = (int) $application['user_id'];

        $this->db->beginTransaction();

        try {
            $this->applications->markReviewed($applicationId, 'approved', $reviewerId, $note);
            $this->setAgencyStatus($userId, 'approved', $reviewerId, $note);
            $this->setRole($userId, 'agency');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Agency approval failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not approve the request.', 'user_id' => null, 'email' => null];
        }

        $this->scriptSync->regenerateForAgencyUser($userId);

        return ['ok' => true, 'error' => null, 'user_id' => $userId, 'email' => (string) $application['applicant_email']];
    }

    /**
     * @return array{ok: bool, error: ?string, user_id: ?int, email: ?string}
     */
    public function reject(int $applicationId, int $reviewerId, ?string $note = null): array
    {
        $application = $this->applications->findById($applicationId);

        if ($application === null || $application['status'] !== 'pending') {
            return ['ok' => false, 'error' => 'That request is not awaiting a decision.', 'user_id' => null, 'email' => null];
        }

        $userId = (int) $application['user_id'];

        $this->db->beginTransaction();

        try {
            $this->applications->markReviewed($applicationId, 'rejected', $reviewerId, $note);
            $this->setAgencyStatus($userId, 'rejected', $reviewerId, $note);
            $this->setRole($userId, 'customer');
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $this->logger->error('Agency rejection failed: ' . $e->getMessage());

            return ['ok' => false, 'error' => 'Could not record the decision.', 'user_id' => null, 'email' => null];
        }

        return ['ok' => true, 'error' => null, 'user_id' => $userId, 'email' => (string) $application['applicant_email']];
    }

    /**
     * Revoke an approved agency without destroying anything.
     *
     * Access and (once §3 lands) branding stop; client links, templates and
     * history survive, because the usage export and the audit trail both have
     * to be able to say what was true last month.
     */
    public function suspend(int $agencyUserId, int $reviewerId, ?string $note = null): bool
    {
        $row = $this->agencyRow($agencyUserId);

        if ($row === null || $row['status'] !== 'approved') {
            return false;
        }

        $this->setAgencyStatus($agencyUserId, 'suspended', $reviewerId, $note);
        $this->setRole($agencyUserId, 'customer');

        // Branding must come back on every banner in the book. Until this runs,
        // a revoked agency's clients are still serving unbranded scripts.
        $this->scriptSync->regenerateForAgencyUser($agencyUserId);

        return true;
    }

    public function reinstate(int $agencyUserId, int $reviewerId, ?string $note = null): bool
    {
        $row = $this->agencyRow($agencyUserId);

        if ($row === null || $row['status'] !== 'suspended') {
            return false;
        }

        $this->setAgencyStatus($agencyUserId, 'approved', $reviewerId, $note);
        $this->setRole($agencyUserId, 'agency');

        $this->scriptSync->regenerateForAgencyUser($agencyUserId);

        return true;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, string>
     */
    private function validate(array $input): array
    {
        $errors = [];

        $company = trim((string) ($input['company_name'] ?? ''));

        if ($company === '') {
            $errors['company_name'] = 'Tell us the company name.';
        } elseif (mb_strlen($company) > 200) {
            $errors['company_name'] = 'That name is too long.';
        }

        $website = trim((string) ($input['website'] ?? ''));

        if ($website !== '' && !filter_var($this->normaliseUrl($website), FILTER_VALIDATE_URL)) {
            $errors['website'] = 'That does not look like a website address.';
        }

        $email = trim((string) ($input['contact_email'] ?? ''));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['contact_email'] = 'That does not look like an email address.';
        }

        if (mb_strlen(trim((string) ($input['pitch'] ?? ''))) > 4000) {
            $errors['pitch'] = 'Please keep this under 4000 characters.';
        }

        return $errors;
    }

    private function normaliseUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        return preg_match('~^https?://~i', $url) === 1 ? $url : 'https://' . $url;
    }

    /** @return array<string, mixed>|null */
    private function agencyRow(int $userId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_agencies WHERE user_id = :userId LIMIT 1',
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        );

        return $row !== false ? $row : null;
    }

    private function setAgencyStatus(int $userId, string $status, int $reviewerId, ?string $note): void
    {
        $this->db->update('oci_agencies', [
            'status' => $status,
            'reviewed_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'reviewed_by' => $reviewerId,
            'review_note' => $note,
            'updated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ], ['user_id' => $userId]);
    }

    /**
     * Flip between customer and agency only.
     *
     * An admin who also runs an agency must not be demoted to customer by a
     * rejection or a suspension — that would take their admin panel away as a
     * side effect of an unrelated decision.
     */
    private function setRole(int $userId, string $role): void
    {
        $this->db->executeStatement(
            "UPDATE oci_users SET role = :role WHERE id = :id AND role <> 'admin'",
            ['role' => $role, 'id' => $userId],
            ['role' => ParameterType::STRING, 'id' => ParameterType::INTEGER],
        );
    }
}
