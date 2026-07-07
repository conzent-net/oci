<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Fix IAB banner field issues:
 *
 * 1. Remove duplicate IAB field categories (migration ran 3x, creating triples)
 * 2. Remove duplicate IAB fields and their translations
 * 3. Remove trailing "<p>Cookie Policy</p>" from iab_notice_description
 *    (it duplicates the privacy_policy_link rendered by the template)
 */
final class Version20260312_001_FixIabDuplicatesAndCookiePolicy extends Migration
{
    public function getDescription(): string
    {
        return 'Deduplicate IAB fields/categories and remove duplicate Cookie Policy text from IAB notice';
    }

    public function up(): void
    {
        // ── 1. Find the canonical (lowest-ID) IAB category ──
        // Keep only the first IAB category; reassign fields, then delete extras.
        $this->sql("
            SET @keep_cat := (
                SELECT MIN(id) FROM oci_banner_field_categories
                WHERE template_id = 1 AND category_key = 'iab'
            )
        ");

        // ── 2. For each field_key, keep only the lowest-ID field under the canonical category ──
        // First, reassign the keeper fields to the canonical category
        $this->sql("
            UPDATE oci_banner_fields bf
            INNER JOIN oci_banner_field_categories bfc ON bfc.id = bf.field_category_id
            SET bf.field_category_id = @keep_cat
            WHERE bfc.category_key = 'iab' AND bfc.template_id = 1
        ");

        // Delete duplicate fields: for each field_key keep only MIN(id)
        $this->sql("
            DELETE bf FROM oci_banner_fields bf
            WHERE bf.field_category_id = @keep_cat
            AND bf.id NOT IN (
                SELECT keeper_id FROM (
                    SELECT MIN(id) AS keeper_id
                    FROM oci_banner_fields
                    WHERE field_category_id = @keep_cat
                    GROUP BY field_key
                ) AS keepers
            )
        ");

        // ── 3. Clean up orphaned translations that reference deleted fields ──
        $this->sql("
            DELETE bft FROM oci_banner_field_translations bft
            LEFT JOIN oci_banner_fields bf ON bf.id = bft.field_id
            WHERE bf.id IS NULL
        ");

        $this->sql("
            DELETE sbft FROM oci_site_banner_field_translations sbft
            LEFT JOIN oci_banner_fields bf ON bf.id = sbft.field_id
            WHERE bf.id IS NULL
        ");

        // ── 4. Deduplicate site_banner_field_translations ──
        // Keep only the row with the highest id for each (site_banner_id, field_id, language_id)
        $this->sql("
            DELETE t1 FROM oci_site_banner_field_translations t1
            INNER JOIN oci_site_banner_field_translations t2
            ON t1.site_banner_id = t2.site_banner_id
               AND t1.field_id = t2.field_id
               AND t1.language_id = t2.language_id
               AND t1.id < t2.id
        ");

        // ── 5. Deduplicate banner_field_translations ──
        $this->sql("
            DELETE t1 FROM oci_banner_field_translations t1
            INNER JOIN oci_banner_field_translations t2
            ON t1.field_id = t2.field_id
               AND t1.language_id = t2.language_id
               AND t1.id < t2.id
        ");

        // ── 6. Delete extra IAB categories (now empty) ──
        $this->sql("
            DELETE FROM oci_banner_field_categories
            WHERE template_id = 1 AND category_key = 'iab' AND id != @keep_cat
        ");

        // ── 7. Remove "<p>Cookie Policy</p>" from iab_notice_description ──
        // This text duplicates the privacy_policy_link already rendered by the Twig template.
        $this->sql("
            UPDATE oci_banner_fields
            SET default_value = REPLACE(default_value, '<p>Cookie Policy</p>', '')
            WHERE field_key = 'iab_notice_description'
        ");

        $this->sql("
            UPDATE oci_banner_field_translations bft
            INNER JOIN oci_banner_fields bf ON bf.id = bft.field_id
            SET bft.label = REPLACE(bft.label, '<p>Cookie Policy</p>', '')
            WHERE bf.field_key = 'iab_notice_description'
        ");

        $this->sql("
            UPDATE oci_site_banner_field_translations sbft
            INNER JOIN oci_banner_fields bf ON bf.id = sbft.field_id
            SET sbft.value = REPLACE(sbft.value, '<p>Cookie Policy</p>', '')
            WHERE bf.field_key = 'iab_notice_description'
        ");
    }

    public function down(): void
    {
        // Restore "<p>Cookie Policy</p>" to iab_notice_description
        $this->sql("
            UPDATE oci_banner_fields
            SET default_value = CONCAT(default_value, '<p>Cookie Policy</p>')
            WHERE field_key = 'iab_notice_description'
        ");

        $this->sql("
            UPDATE oci_banner_field_translations bft
            INNER JOIN oci_banner_fields bf ON bf.id = bft.field_id
            SET bft.label = CONCAT(bft.label, '<p>Cookie Policy</p>')
            WHERE bf.field_key = 'iab_notice_description'
        ");

        $this->sql("
            UPDATE oci_site_banner_field_translations sbft
            INNER JOIN oci_banner_fields bf ON bf.id = sbft.field_id
            SET sbft.value = CONCAT(sbft.value, '<p>Cookie Policy</p>')
            WHERE bf.field_key = 'iab_notice_description'
        ");
    }
}
