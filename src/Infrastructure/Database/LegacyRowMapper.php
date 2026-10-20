<?php

declare(strict_types=1);

namespace OCI\Infrastructure\Database;

/**
 * v1 row in, v2 row out. Pure functions, no database, no edition knowledge.
 *
 * Two importers share this: the bulk {@see LegacyMigrationRunner} (a whole
 * v1 dump loaded next to the v2 tables) and the per-account importer in the
 * cloud edition's LegacyMigration module (one account at a time over the live
 * v1 connection). Both used to carry their own copy of "which v1 column goes
 * where", and the copies had already drifted: the runner mapped v1 user
 * levels that do not exist (9 and 5; the real ones are 3, 20 and 30) and
 * wrote agency columns that a later migration dropped.
 *
 * Every method returns exactly the columns the target table has in the core
 * schema. Columns that exist only in the cloud edition (the `legacy_import*`
 * stamps) are the caller's business, never emitted here, so the open-source
 * runner keeps working against the open-source schema.
 *
 * v1 user levels (legacy/app/admin/includes/constants.php and
 * custom_functions.php): 1 and 2 are staff, 10 is the super admin, 3 is a
 * customer, 20 an agency, 30 a reseller. Staff never become v2 users of any
 * role; the caller asks {@see isStaffLevel()} first.
 */
final class LegacyRowMapper
{
    public const LEVEL_CUSTOMER = 3;
    public const LEVEL_AGENCY = 20;
    public const LEVEL_RESELLER = 30;

    /** @var list<int> the levels a v2 account can be made from */
    public const IMPORTABLE_LEVELS = [self::LEVEL_CUSTOMER, self::LEVEL_AGENCY, self::LEVEL_RESELLER];

    public static function isStaffLevel(int $level): bool
    {
        return !\in_array($level, self::IMPORTABLE_LEVELS, true);
    }

    /** Agencies and resellers both hold the v2 agency role; the reseller nuance is on the agency row. */
    public static function roleForLevel(int $level): string
    {
        return $level === self::LEVEL_AGENCY || $level === self::LEVEL_RESELLER ? 'agency' : 'customer';
    }

    /**
     * v1 `users` → `oci_users` (without company columns).
     *
     * The password is copied verbatim: v1 wrote bcrypt hashes with
     * `password_hash()`, which `UserRepository::create()` passes through
     * untouched and `AuthService` verifies. A v1 customer logs in to v2 with
     * the password they already have.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function user(array $row, int $legacyId): array
    {
        $createdAt = self::unixToDatetime((int) ($row['regdate'] ?? 0));
        $email = trim((string) ($row['email'] ?? ''));

        return [
            'legacy_id' => $legacyId,
            'username' => (string) ($row['username'] ?? '') !== '' ? (string) $row['username'] : "user_{$legacyId}",
            'email' => $email !== '' ? $email : "unknown_{$legacyId}@legacy.local",
            'first_name' => (string) ($row['firstname'] ?? ''),
            'last_name' => (string) ($row['lastname'] ?? ''),
            'password' => (string) ($row['password'] ?? ''),
            'role' => self::roleForLevel((int) ($row['userlevel'] ?? self::LEVEL_CUSTOMER)),
            'is_active' => 1,
            'is_enterprise' => (int) ($row['enterprise_account'] ?? 0) === 1 ? 1 : 0,
            'account_id' => self::stripeCustomerId($row['account_id'] ?? null),
            'price_model' => ($row['price_model'] ?? '') !== '' ? (string) $row['price_model'] : null,
            'last_login_ip' => ($row['lastip'] ?? '') !== '' ? (string) $row['lastip'] : null,
            'login_attempts' => (int) ($row['user_login_attempts'] ?? 0),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    /**
     * v1 `users_company` → `oci_user_companies` (without `user_id`).
     *
     * @param array<string, mixed> $row the `users_company` row, or a `users` row joined with it
     *
     * @return array<string, mixed>
     */
    public static function company(array $row): array
    {
        return [
            'company_name' => self::nullable($row['company'] ?? null),
            'vat_number' => self::nullable($row['vat'] ?? null),
            'address' => self::nullable($row['address'] ?? null),
            'zip' => self::nullable($row['zip'] ?? null),
            'city' => self::nullable($row['city'] ?? null),
            'state' => self::nullable($row['state'] ?? null),
            'country_code' => self::nullable($row['country'] ?? null),
            'phone' => self::nullable($row['phone'] ?? null),
        ];
    }

    /** True when the company row carries anything worth writing. */
    public static function companyHasContent(array $row): bool
    {
        foreach (self::company($row) as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * v1 `user_sites` → `oci_sites`. The website key is copied verbatim: it is
     * the identifier every customer has embedded in their pages and every
     * GTM tag carries, and the whole point of the import is that it survives.
     *
     * v1 `status`: 1 live, 3 deleted (the v1 code filters `status <> 3`
     * everywhere); anything else is inactive.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function site(array $row, int $legacyId, int $ociUserId): array
    {
        $created = self::datetime($row['created_date'] ?? null);

        return [
            'legacy_id' => $legacyId,
            'user_id' => $ociUserId,
            'site_name' => self::nullable($row['site_name'] ?? null),
            'domain' => (string) ($row['domain'] ?? ''),
            'website_key' => (string) ($row['website_key'] ?? ''),
            'status' => ((int) ($row['status'] ?? 1)) === 1 ? 'active' : 'inactive',
            'setup_status' => (int) ($row['setup_status'] ?? 0),
            'consent_log_enabled' => (int) ($row['consent_log'] ?? 1),
            'consent_sharing_enabled' => (int) ($row['consent_sharing'] ?? 1),
            'gcm_enabled' => (int) ($row['support_gcm'] ?? 1),
            'tag_fire_enabled' => (int) ($row['allow_tag_fire'] ?? 1),
            'cross_domain_enabled' => (int) ($row['allow_cross_domain'] ?? 0),
            'block_iframe' => (int) ($row['block_iframe'] ?? 0),
            'debug_mode' => (int) ($row['debug_mode'] ?? 0),
            'display_banner_type' => ($row['display_banner'] ?? '') !== '' ? (string) $row['display_banner'] : 'gdpr',
            'banner_delay_ms' => (int) ($row['banner_delay'] ?? 2000),
            'include_all_languages' => (int) ($row['include_all_lang'] ?? 1),
            'privacy_policy_url' => self::nullable($row['privacy_policy'] ?? null),
            'other_domains' => self::nullable($row['other_domains'] ?? null),
            'disable_on_pages' => self::nullable($row['disable_on_pages'] ?? null),
            'compliant_status' => self::nullable($row['compliant_status'] ?? null),
            'gcm_config_status' => self::nullable($row['gcm_config_status'] ?? null),
            'template_applied' => self::nullable($row['template_applied'] ?? null),
            'site_logo' => self::nullable($row['site_logo'] ?? null),
            'icon_logo' => self::nullable($row['icon_logo'] ?? null),
            'renew_user_consent_at' => self::nullable($row['renew_user_consent'] ?? null),
            'last_banner_load_at' => self::nullable($row['last_banner_load_on'] ?? null),
            'site_updated' => (int) ($row['site_updated'] ?? 0),
            'created_by' => $ociUserId,
            'created_at' => $created,
            'updated_at' => self::datetime($row['modified_date'] ?? null, $created),
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `associated_sites`
     *
     * @return array<string, mixed>
     */
    public static function associatedSite(array $row, int $ociSiteId): array
    {
        return [
            'site_id' => $ociSiteId,
            'domain' => (string) ($row['domain'] ?? ''),
            'privacy_policy_url' => self::nullable($row['privacy_policy'] ?? null),
            'created_at' => self::datetime($row['created_date'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `user_languages`
     *
     * @return array<string, mixed>
     */
    public static function siteLanguage(array $row, int $ociSiteId, int $ociLanguageId): array
    {
        return [
            'site_id' => $ociSiteId,
            'language_id' => $ociLanguageId,
            'is_default' => (int) ($row['is_default'] ?? 0) === 1 ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `user_cookies`
     *
     * @return array<string, mixed>
     */
    public static function siteCookie(array $row, int $ociSiteId, ?int $ociCategoryId): array
    {
        $created = self::datetime($row['created_at'] ?? null);

        return [
            'site_id' => $ociSiteId,
            'category_id' => $ociCategoryId,
            'cookie_name' => ($row['cookie_name'] ?? '') !== '' ? (string) $row['cookie_name'] : 'unknown',
            'cookie_domain' => self::nullable($row['cookie_domain'] ?? null),
            'default_duration' => self::nullable($row['default_duration'] ?? null),
            'script_url_pattern' => self::nullable($row['script_url_pattern'] ?? null),
            'from_scan' => (int) ($row['created_from_scan'] ?? 1),
            'created_at' => $created,
            'updated_at' => self::datetime($row['updated_at'] ?? null, $created),
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `user_cookie_policies`
     *
     * @return array<string, mixed>
     */
    public static function cookiePolicy(array $row, int $ociSiteId, int $ociLanguageId): array
    {
        $created = self::datetime($row['created_at'] ?? null);

        return [
            'site_id' => $ociSiteId,
            'language_id' => $ociLanguageId,
            'heading' => self::nullable($row['heading'] ?? null),
            'type_heading' => self::nullable($row['type_heading'] ?? null),
            'url_key' => self::nullable($row['url_key'] ?? null),
            'preference_heading' => self::nullable($row['preference_heading'] ?? null),
            'preference_description' => self::nullable($row['preference_description'] ?? null),
            'revisit_consent_widget' => self::nullable($row['revisit_consent_widget'] ?? null),
            'policy_content' => self::nullable($row['policy_content'] ?? null),
            'show_audit_table' => (int) ($row['show_audit_table'] ?? 0),
            'effective_date' => self::dateOnly($row['effective_date'] ?? null),
            'created_at' => $created,
            'updated_at' => self::datetime($row['updated_at'] ?? null, $created),
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `user_privacy_policies`
     *
     * @return array<string, mixed>
     */
    public static function privacyPolicy(array $row, int $ociSiteId, int $ociLanguageId): array
    {
        $created = self::datetime($row['created_at'] ?? null);

        return [
            'site_id' => $ociSiteId,
            'language_id' => $ociLanguageId,
            'heading' => self::nullable($row['heading'] ?? null),
            'url_key' => self::nullable($row['url_key'] ?? null),
            'step_data' => self::nullable($row['step_data'] ?? null),
            'policy_content' => self::nullable($row['policy_content'] ?? null),
            'effective_date' => self::dateOnly($row['effective_date'] ?? null),
            'created_at' => $created,
            'updated_at' => self::datetime($row['updated_at'] ?? null, $created),
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `api`
     *
     * @return array<string, mixed>
     */
    public static function apiKey(array $row, int $ociUserId): array
    {
        return [
            'user_id' => $ociUserId,
            'api_key' => (string) ($row['apikey'] ?? ''),
            'name' => 'Imported from conzent.net',
            'is_active' => (int) ($row['active'] ?? 1) === 1 ? 1 : 0,
            'created_at' => self::datetime($row['created'] ?? null),
        ];
    }

    /**
     * v1 `agencies` → `oci_agencies`, without the bank and commission columns
     * that Version20260906_001 dropped and without `status`, which the caller
     * decides (the importer writes `approved` or `suspended`; the runner
     * leaves the column default).
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function agency(array $row, int $legacyId, int $ociUserId): array
    {
        $created = self::datetime($row['created'] ?? null);

        return [
            'legacy_id' => $legacyId,
            'user_id' => $ociUserId,
            'name' => ($row['name'] ?? '') !== '' ? (string) $row['name'] : "Agency {$legacyId}",
            'address' => self::nullable($row['address'] ?? null),
            'zip' => self::nullable($row['zip'] ?? null),
            'city' => self::nullable($row['city'] ?? null),
            'state' => self::nullable($row['state'] ?? null),
            'country_code' => self::nullable($row['country_code'] ?? null),
            'vat_number' => self::nullable($row['vat_number'] ?? null),
            'contact_name' => self::nullable($row['contact'] ?? null),
            'contact_email' => self::nullable($row['contact_email'] ?? null),
            'invoice_email' => self::nullable($row['invoice_email'] ?? null),
            'agency_type' => ((int) ($row['agency_type'] ?? 0)) === 1 ? 'reseller' : 'agency',
            'is_active' => (int) ($row['enabled'] ?? 1) === 1 ? 1 : 0,
            'created_at' => $created,
            'updated_at' => self::datetime($row['updated'] ?? null, $created),
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `agencies_customers`
     *
     * @return array<string, mixed>
     */
    public static function agencyCustomer(array $row, int $ociAgencyId, int $ociCustomerUserId): array
    {
        return [
            'agency_id' => $ociAgencyId,
            'customer_user_id' => $ociCustomerUserId,
            'date_from' => self::dateOnly($row['date_from'] ?? null),
            // Never carried over from v1. There, `date_to` is written equal to
            // `date_from` when the client is assigned and a client who leaves
            // has the row deleted, so copying it would close every link the
            // moment it arrived. In v2 the column means what it says.
            'date_to' => null,
        ];
    }

    /**
     * @param array<string, mixed> $row v1 `site_wizards`
     *
     * @return array<string, mixed>
     */
    public static function siteWizard(array $row, int $ociSiteId, int $ociUserId): array
    {
        $created = self::datetime($row['created_at'] ?? null);

        return [
            'site_id' => $ociSiteId,
            'user_id' => $ociUserId,
            'languages' => self::nullable($row['languages'] ?? null),
            'banner_type' => self::nullable($row['banner_type'] ?? null),
            'ads_type' => (int) ($row['ads_type'] ?? 0),
            'ad_options' => self::nullable($row['ad_options'] ?? null),
            'status' => (int) ($row['status'] ?? 0),
            'last_step' => (int) ($row['last_step'] ?? 0),
            'created_at' => $created,
            'updated_at' => self::datetime($row['updated_at'] ?? null, $created),
        ];
    }

    /** A v1 `account_id` is a Stripe customer id only when it looks like one; legacy rows hold a hex hash. */
    public static function stripeCustomerId(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return str_starts_with($value, 'cus_') ? $value : null;
    }

    public static function unixToDatetime(int $timestamp): string
    {
        return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : date('Y-m-d H:i:s');
    }

    /** A v1 datetime string, a unix timestamp, or nothing → a MySQL datetime (fallback: now). */
    public static function datetime(mixed $value, ?string $fallback = null): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0' || $value === '0000-00-00 00:00:00') {
            return $fallback ?? date('Y-m-d H:i:s');
        }
        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        return (string) $value;
    }

    public static function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private static function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;

        return $value !== '' ? $value : null;
    }
}
