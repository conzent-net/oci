<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Agency scoping, part 1: make the membership table trustworthy enough to be an
 * authorization boundary.
 *
 * Everything the agency feature does — reading a client's sites, applying a
 * settings template, counting clients for white-label — resolves through a JOIN
 * on `oci_agency_customers`. That makes these two tables the access-control
 * layer, and they currently have three defects that only matter once something
 * relies on them.
 *
 * - **`oci_agencies` has no unique key on `user_id`.** `findOrCreateByUserId()`
 *   is a read-then-insert with no constraint underneath it, so two concurrent
 *   requests can both miss and both insert. The middleware would then pick one
 *   row arbitrarily and half an agency's clients would vanish depending on
 *   which one it picked — the kind of bug that looks like data loss and is
 *   actually a missing index.
 *
 * - **`date_to` exists and is never written or read.** Unlink deletes the row
 *   today, which destroys the history the per-client usage export needs, and
 *   leaves no way to answer "was this client ours in March". From here, only
 *   `date_to IS NULL` counts as an active link and unlink sets the date.
 *
 * - **A customer can be claimed by several agencies at once.** The unique key
 *   is `(agency_id, customer_user_id)`, so it stops one agency adding the same
 *   client twice and does nothing about two agencies both holding a live link
 *   to the same client — legacy's one-agency-per-customer rule was never
 *   ported. Both would see that client's data.
 *
 * **This migration refuses to run rather than destroying anything.** Duplicate
 * agency rows and multiply-claimed customers are both reported by exception
 * with the offending ids, because deciding which of two agency rows is the real
 * one, or which agency owns a contested client, is a business call and not
 * something a schema change should make silently at 3am during a deploy. The
 * expected production state is zero of both (2 agencies, 1 link as of
 * 2026-09-04), so this is a guard, not a chore.
 *
 * ## One row per relationship, and the rule that makes it safe
 *
 * The `(agency_id, customer_user_id)` unique key goes. It made "a client who
 * left and came back" impossible to record honestly: the only way to re-link
 * was to revive the old row, and reviving it reset `date_from`, which erased
 * the earlier period from every usage report. Each link is now its own row
 * with its own dates.
 *
 * What replaces it is the rule that actually matters — **at most one ACTIVE
 * link per customer, across all agencies** — and it is a real index, not a
 * convention. MariaDB cannot put a WHERE on a unique index, but it can index a
 * generated column, so `active_customer` is `customer_user_id` while the link
 * is open and NULL once it has ended, and NULLs never collide. The service
 * layer still checks first so a person gets a readable message; the index is
 * for the race the check cannot see.
 */
final class Version20260905_001_AgencyCustomerLifecycle extends Migration
{
    public function getDescription(): string
    {
        return 'Agency lifecycle: unique agency per user, active-link dates, and the indexes the scoping join needs';
    }

    public function up(): void
    {
        $this->guardDuplicateAgencies();
        $this->guardMultiplyClaimedCustomers();

        // The unique key both prevents the race and satisfies the existing
        // foreign key's index requirement, which is why the redundant
        // non-unique index can go immediately afterwards and not before.
        $this->sql('
            ALTER TABLE `oci_agencies`
            ADD UNIQUE KEY `uq_oci_agency_user` (`user_id`)
        ');

        $this->sql('
            ALTER TABLE `oci_agencies`
            DROP INDEX `idx_oci_agency_user`
        ');

        // Every existing link is active by definition — nothing has ever
        // written date_to. Without a date_from they would all report as
        // "managed since unknown" in the usage export, so seed them from the
        // agency's own creation date, which is the closest true-ish answer
        // available and is never later than the link itself.
        $this->sql('
            UPDATE `oci_agency_customers` ac
            JOIN `oci_agencies` a ON a.`id` = ac.`agency_id`
            SET ac.`date_from` = DATE(a.`created_at`)
            WHERE ac.`date_from` IS NULL
        ');

        // The scoping join filters on (agency_id, date_to); the reverse lookup
        // "which agency manages this user" filters on (customer_user_id,
        // date_to). The existing unique key covers neither, because it leads
        // with agency_id and does not carry date_to.
        $this->sql('
            ALTER TABLE `oci_agency_customers`
            ADD INDEX `idx_oci_agcust_active` (`agency_id`, `date_to`),
            ADD INDEX `idx_oci_agcust_customer` (`customer_user_id`, `date_to`)
        ');

        // The pair key was what stopped a relationship being recorded twice —
        // once per period it was actually true. The foreign key on agency_id
        // needs an index to lean on and idx_oci_agcust_active now provides
        // one, which is why the drop comes after the add above.
        $this->sql('
            ALTER TABLE `oci_agency_customers`
            DROP INDEX `uq_oci_agcust`
        ');

        // customer_user_id while open, NULL once ended. Unique over that is
        // "one open link per customer" — the partial unique index MariaDB does
        // not otherwise have.
        $this->sql('
            ALTER TABLE `oci_agency_customers`
            ADD COLUMN `active_customer` INT UNSIGNED
                GENERATED ALWAYS AS (IF(`date_to` IS NULL, `customer_user_id`, NULL)) VIRTUAL
                COMMENT "customer_user_id while the link is open; NULL once ended; unique = one agency at a time",
            ADD UNIQUE KEY `uq_oci_agcust_one_open` (`active_customer`)
        ');
    }

    public function down(): void
    {
        // Restoring the pair key is only possible if no relationship has been
        // recorded twice. Refuse rather than delete history: which of a
        // client's two periods to keep is not a schema decision.
        $dupes = $this->db->fetchAllAssociative('
            SELECT `agency_id`, `customer_user_id`, COUNT(*) AS `n`
            FROM `oci_agency_customers`
            GROUP BY `agency_id`, `customer_user_id`
            HAVING `n` > 1
        ');

        if ($dupes !== []) {
            throw new \RuntimeException(sprintf(
                'Cannot restore the (agency_id, customer_user_id) unique key: %d relationship(s) have more than one row. '
                . 'Merge or delete the extra rows by hand, then re-run the rollback.',
                \count($dupes),
            ));
        }

        $this->sql('
            ALTER TABLE `oci_agency_customers`
            DROP INDEX `uq_oci_agcust_one_open`,
            DROP COLUMN `active_customer`
        ');

        $this->sql('
            ALTER TABLE `oci_agency_customers`
            ADD UNIQUE KEY `uq_oci_agcust` (`agency_id`, `customer_user_id`)
        ');

        $this->sql('
            ALTER TABLE `oci_agency_customers`
            DROP INDEX `idx_oci_agcust_active`,
            DROP INDEX `idx_oci_agcust_customer`
        ');

        $this->sql('
            ALTER TABLE `oci_agencies`
            ADD INDEX `idx_oci_agency_user` (`user_id`)
        ');

        $this->sql('
            ALTER TABLE `oci_agencies`
            DROP INDEX `uq_oci_agency_user`
        ');

        // date_from is deliberately not un-backfilled. Reverting the schema
        // should not throw away a fact that is now true.
    }

    /**
     * Abort with the ids rather than letting ALTER TABLE fail on the index.
     *
     * "Duplicate entry '42' for key 'uq_oci_agency_user'" names one value and
     * leaves the operator to find the rest by hand, mid-deploy.
     */
    private function guardDuplicateAgencies(): void
    {
        $rows = $this->db->fetchAllAssociative('
            SELECT `user_id`, COUNT(*) AS `n`, GROUP_CONCAT(`id` ORDER BY `id`) AS `ids`
            FROM `oci_agencies`
            GROUP BY `user_id`
            HAVING `n` > 1
        ');

        if ($rows === []) {
            return;
        }

        $detail = implode('; ', array_map(
            static fn (array $r): string => sprintf('user %d has agency rows %s', (int) $r['user_id'], (string) $r['ids']),
            $rows,
        ));

        throw new \RuntimeException(
            'Cannot add the unique agency-per-user key: ' . $detail . '. '
            . 'Two agency rows for one user means half that agency\'s customers are attached to a row nothing reads. '
            . 'Merge the customer links onto the row you are keeping, delete the other, then re-run this migration. '
            . 'This is not done automatically because which row survives decides which customers an agency can still see.',
        );
    }

    /**
     * Report customers held by more than one agency. Never resolve it here.
     */
    private function guardMultiplyClaimedCustomers(): void
    {
        $rows = $this->db->fetchAllAssociative('
            SELECT `customer_user_id`, COUNT(*) AS `n`, GROUP_CONCAT(`agency_id` ORDER BY `agency_id`) AS `agencies`
            FROM `oci_agency_customers`
            WHERE `date_to` IS NULL
            GROUP BY `customer_user_id`
            HAVING `n` > 1
        ');

        if ($rows === []) {
            return;
        }

        $detail = implode('; ', array_map(
            static fn (array $r): string => sprintf('customer user %d is claimed by agencies %s', (int) $r['customer_user_id'], (string) $r['agencies']),
            $rows,
        ));

        throw new \RuntimeException(
            'Cannot establish single-agency ownership: ' . $detail . '. '
            . 'Both agencies can currently read that customer\'s sites and consent data. '
            . 'Set `date_to` on the links that should end, then re-run this migration. '
            . 'Choosing for you would silently revoke a real agency\'s access to a real client.',
        );
    }
}
