<?php

declare(strict_types=1);

namespace OCI\Database\Migrations;

use OCI\Infrastructure\Database\Migration;

/**
 * Fix CCPA banner layout_key: column default was 'gdpr/classic' which caused
 * CCPA banners to render with the GDPR layout instead of ccpa/classic.
 */
final class Version20260329_001_FixCcpaBannerLayoutKey extends Migration
{
    public function getDescription(): string
    {
        return 'Fix CCPA banner layout_key default and update existing CCPA banners';
    }

    public function up(): void
    {
        // 1. Change column default from 'gdpr/classic' to NULL
        $this->db->executeStatement(
            "ALTER TABLE oci_site_banners ALTER COLUMN layout_key SET DEFAULT NULL"
        );

        // 2. Fix existing CCPA banners that inherited the wrong default
        $this->db->executeStatement(
            "UPDATE oci_site_banners sb
             SET sb.layout_key = 'ccpa/classic'
             WHERE sb.layout_key = 'gdpr/classic'
               AND sb.banner_template_id IN (
                   SELECT id FROM oci_banner_templates
                   WHERE cookie_laws LIKE '%\"ccpa\":1%' OR cookie_laws = 'ccpa'
               )"
        );
    }

    public function down(): void
    {
        $this->db->executeStatement(
            "ALTER TABLE oci_site_banners ALTER COLUMN layout_key SET DEFAULT 'gdpr/classic'"
        );
    }
}
