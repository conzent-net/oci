<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Two fixes to agency invites: a unique token, and invites to people who do
 * not have an account yet.
 *
 * ## The token was indexed but not unique
 *
 * `idx_oci_agency_invites_token` is a plain KEY. Tokens are SHA-256 hashes so
 * a natural collision is not the concern — the concern is that `acceptInvite()`
 * looks an invite up by token and takes `LIMIT 1`. Any path that ever produced
 * two rows with the same token would silently accept an arbitrary one of them,
 * and a uniqueness constraint is the difference between that being impossible
 * and it being merely unlikely. One line, and the index already exists.
 *
 * ## `target_user_id` made invites useless for real onboarding
 *
 * An invite required an existing account, so an agency could only invite
 * clients who had already signed up for Conzent themselves. That is backwards:
 * the agency is the one bringing the client. `email` becomes the address an
 * invite is aimed at, and `target_user_id` becomes nullable — filled in when
 * the person accepts, or when they already had an account.
 *
 * The column is added **nullable with no backfill of a fake value**: every
 * existing invite genuinely has a user id and no email, and inventing one to
 * make the schema tidy would be inventing data.
 *
 * A row now needs one or the other, which MariaDB cannot express as a CHECK on
 * older versions, so it is stated here and enforced in the service layer.
 */
final class Version20260905_006_AgencyInviteHardening extends Migration
{
    public function getDescription(): string
    {
        return 'Unique invite tokens, and invites addressed to an email rather than an existing account';
    }

    public function up(): void
    {
        $dupes = $this->db->fetchAllAssociative(
            'SELECT `token`, COUNT(*) AS n FROM `oci_agency_invites` GROUP BY `token` HAVING n > 1',
        );

        if ($dupes !== []) {
            throw new \RuntimeException(sprintf(
                'Cannot make invite tokens unique: %d token(s) appear more than once. '
                . 'Decide which invite is real, delete the others, then re-run.',
                \count($dupes),
            ));
        }

        // Backfill the email from the account each existing invite points at,
        // so no row is left without a way to say who it was for.
        $this->sql('
            ALTER TABLE `oci_agency_invites`
            ADD COLUMN `email` VARCHAR(255) NULL DEFAULT NULL
                COMMENT "who the invite is for; used when target_user_id is NULL"
                AFTER `target_user_id`
        ');

        $this->sql('
            UPDATE `oci_agency_invites` AS ai
            INNER JOIN `oci_users` AS u ON u.`id` = ai.`target_user_id`
            SET ai.`email` = u.`email`
            WHERE ai.`email` IS NULL
        ');

        // Nullable only AFTER the backfill, so nothing is briefly unattributable.
        $this->sql('
            ALTER TABLE `oci_agency_invites`
            MODIFY COLUMN `target_user_id` INT UNSIGNED NULL DEFAULT NULL
        ');

        $this->sql('
            ALTER TABLE `oci_agency_invites`
            DROP INDEX `idx_oci_agency_invites_token`,
            ADD UNIQUE KEY `uq_oci_agency_invites_token` (`token`),
            ADD INDEX `idx_oci_agency_invites_email` (`email`, `status`)
        ');
    }

    public function down(): void
    {
        // Rows created against an email with no account cannot satisfy a
        // NOT NULL target_user_id, so they are removed rather than blocking
        // the rollback with a constraint failure. Said out loud because it is
        // data loss, even if it is data this schema cannot represent.
        $this->sql('DELETE FROM `oci_agency_invites` WHERE `target_user_id` IS NULL');

        $this->sql('
            ALTER TABLE `oci_agency_invites`
            DROP INDEX `uq_oci_agency_invites_token`,
            DROP INDEX `idx_oci_agency_invites_email`,
            ADD INDEX `idx_oci_agency_invites_token` (`token`)
        ');

        $this->sql('
            ALTER TABLE `oci_agency_invites`
            MODIFY COLUMN `target_user_id` INT UNSIGNED NOT NULL
        ');

        $this->sql('ALTER TABLE `oci_agency_invites` DROP COLUMN `email`');
    }
}
