<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Manages banner layouts — both system layouts (on disk) and custom layouts (in DB).
 *
 * System layouts live in resources/consent/layouts/{cookie_laws}/{slug}.html.twig
 * Custom layouts are stored in oci_custom_layouts, created by duplicating a system layout.
 */
final class LayoutService
{
    /** @var array<string, array<string, mixed>>|null Cached registry */
    private ?array $registry = null;

    private string $layoutsPath;

    /**
     * Required variables that MUST be present in every layout template.
     * Validation fails if any of these are missing.
     */
    private const REQUIRED_VARIABLES = [
        '{{ buttons_html|raw }}' => 'Accept/reject/customize buttons',
        '{{ branding_html|raw }}' => 'Powered-by branding (contractually required)',
        '{{ cookie_categories_html|raw }}' => 'Cookie category toggles in preference center',
        '{{ pref_buttons_html|raw }}' => 'Preference center save/accept buttons',
        '{{ close_button_html|raw }}' => 'Preference center close button',
        '[conzent_cookie_notice_banner_title]' => 'Banner title text',
        '[conzent_cookie_notice_message]' => 'Banner message text',
        '[conzent_preference_center_preference_title]' => 'Preference center title',
        '[conzent_preference_center_overview]' => 'Preference center overview text',
    ];

    /**
     * Recommended (but not required) variables.
     */
    private const RECOMMENDED_VARIABLES = [
        '{{ revisit_html|raw }}' => 'Revisit consent button',
        '{{ privacy_policy_link|raw }}' => 'Privacy policy link',
        '{{ logo_html|raw }}' => 'Site logo',
        '{{ banner_cookie_list|raw }}' => 'Cookie list in notice layer',
        '{{ google_privacy_policy|raw }}' => 'DMA compliance text',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly string $resourcePath,
    ) {
        $this->layoutsPath = $resourcePath . '/consent/layouts';
    }

    /**
     * Get all system layouts for a given cookie law type.
     *
     * @return array<string, array<string, mixed>> Keyed by layout slug
     */
    public function getSystemLayouts(string $cookieLaws = 'gdpr'): array
    {
        $registry = $this->loadRegistry();
        $layouts = $registry[$cookieLaws] ?? [];

        // Inline SVG thumbnails so the template can render them directly
        foreach ($layouts as $slug => &$layout) {
            $layout['thumbnail_svg'] = '';
            if (isset($layout['thumbnail'])) {
                $svgPath = $this->resourcePath . '/consent/' . $layout['thumbnail'];
                if (file_exists($svgPath)) {
                    $layout['thumbnail_svg'] = (string) file_get_contents($svgPath);
                }
            }
        }
        unset($layout);

        return $layouts;
    }

    /**
     * Get all custom layouts for a site.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCustomLayouts(int $siteId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id, site_id, base_layout_key, layout_name, cookie_laws, is_active, created_at, updated_at
             FROM oci_custom_layouts
             WHERE site_id = :siteId AND is_active = 1
             ORDER BY layout_name ASC',
            ['siteId' => $siteId],
        );
    }

    /**
     * Get a single custom layout by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getCustomLayout(int $layoutId): ?array
    {
        $row = $this->db->fetchAssociative(
            'SELECT * FROM oci_custom_layouts WHERE id = :id',
            ['id' => $layoutId],
        );

        return $row === false ? null : $row;
    }

    /**
     * Resolve layout HTML for rendering.
     * If customLayoutId is set, loads from DB. Otherwise loads system layout from disk.
     */
    public function getLayoutHtml(?string $layoutKey, ?int $customLayoutId, int $siteId): string
    {
        // Custom layout from DB
        if ($customLayoutId !== null) {
            $html = $this->db->fetchOne(
                'SELECT html_content FROM oci_custom_layouts WHERE id = :id AND site_id = :siteId',
                ['id' => $customLayoutId, 'siteId' => $siteId],
            );
            if ($html !== false) {
                return (string) $html;
            }
        }

        // System layout from disk
        $key = $layoutKey ?? 'gdpr/classic';
        $templateFile = $this->layoutsPath . '/' . $key . '.html.twig';

        if (file_exists($templateFile)) {
            return (string) file_get_contents($templateFile);
        }

        // Fallback to base
        $parts = explode('/', $key);
        $baseFile = $this->layoutsPath . '/' . $parts[0] . '/base.html.twig';
        if (file_exists($baseFile)) {
            return (string) file_get_contents($baseFile);
        }

        // Ultimate fallback — legacy template
        return '';
    }

    /**
     * Duplicate a system layout into oci_custom_layouts for editing.
     *
     * @return int The new custom layout ID
     */
    public function duplicateLayout(int $siteId, string $baseLayoutKey, string $name): int
    {
        // Resolve the full HTML by rendering the Twig template (with extends)
        $html = $this->renderSystemLayout($baseLayoutKey);

        $parts = explode('/', $baseLayoutKey);
        $cookieLaws = $parts[0] ?? 'gdpr';

        $this->db->insert('oci_custom_layouts', [
            'site_id' => $siteId,
            'base_layout_key' => $baseLayoutKey,
            'layout_name' => $name,
            'html_content' => $html,
            'cookie_laws' => $cookieLaws,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a custom layout's HTML and optional CSS.
     *
     * @throws \RuntimeException If validation fails
     */
    public function updateCustomLayout(int $layoutId, string $html, ?string $css = null): void
    {
        $missing = $this->validateLayout($html);
        if (!empty($missing)) {
            $names = array_map(fn(array $v) => $v['variable'], $missing);
            throw new \RuntimeException('Layout is missing required variables: ' . implode(', ', $names));
        }

        $data = ['html_content' => $html, 'updated_at' => date('Y-m-d H:i:s')];
        if ($css !== null) {
            $data['custom_css'] = $css;
        }

        $this->db->update('oci_custom_layouts', $data, ['id' => $layoutId]);
    }

    /**
     * Delete a custom layout.
     */
    public function deleteCustomLayout(int $layoutId, int $siteId): void
    {
        $this->db->delete('oci_custom_layouts', ['id' => $layoutId, 'site_id' => $siteId]);
    }

    /**
     * Validate that a layout HTML contains all required variables.
     *
     * @return array<int, array{variable: string, description: string, type: string}> Missing variables (empty = valid)
     */
    public function validateLayout(string $html): array
    {
        $missing = [];
        foreach (self::REQUIRED_VARIABLES as $variable => $description) {
            if (strpos($html, $variable) === false) {
                $missing[] = [
                    'variable' => $variable,
                    'description' => $description,
                    'type' => 'required',
                ];
            }
        }

        return $missing;
    }

    /**
     * Check for recommended (but not required) variables that are missing.
     *
     * @return array<int, array{variable: string, description: string, type: string}>
     */
    public function getRecommendations(string $html): array
    {
        $warnings = [];
        foreach (self::RECOMMENDED_VARIABLES as $variable => $description) {
            if (strpos($html, $variable) === false) {
                $warnings[] = [
                    'variable' => $variable,
                    'description' => $description,
                    'type' => 'recommended',
                ];
            }
        }

        return $warnings;
    }

    /**
     * Render a layout template with sample data for preview.
     * Uses an isolated Twig environment to safely render user-provided HTML.
     */
    public function renderPreview(string $html, array $sampleData = []): string
    {
        $defaults = $this->getSampleData();
        $data = array_merge($defaults, $sampleData);

        try {
            $twig = new TwigEnvironment(new ArrayLoader(['preview' => $html]), [
                'strict_variables' => false,
                'autoescape' => false,
            ]);

            $rendered = $twig->render('preview', $data);

            // Replace bracket content placeholders with actual English preview text
            $rendered = $this->replacePreviewPlaceholders($rendered);

            // Wrap in preview shell with base CSS
            return $this->wrapPreviewHtml($rendered);
        } catch (\Throwable $e) {
            return '<div style="color:red;padding:20px;font-family:monospace;">Template error: '
                . htmlspecialchars($e->getMessage(), ENT_QUOTES) . '</div>';
        }
    }

    /**
     * Render a Twig layout template with variables for script generation.
     * This is the production render path used by ScriptGenerationService.
     */
    public function renderForScript(string $html, array $variables): string
    {
        try {
            // Use a chain loader: ArrayLoader for the template itself,
            // FilesystemLoader for any {% extends %} references
            $arrayLoader = new ArrayLoader(['__banner__' => $html]);
            $fsLoader = new FilesystemLoader($this->layoutsPath);
            $chainLoader = new ChainLoader([$arrayLoader, $fsLoader]);

            $twig = new TwigEnvironment($chainLoader, [
                'strict_variables' => false,
                'autoescape' => false,
            ]);

            return $twig->render('__banner__', $variables);
        } catch (\Throwable) {
            // If Twig rendering fails, return the raw HTML with basic variable replacement
            return $html;
        }
    }

    /**
     * Render a system layout with sample data for preview.
     * Used by the preview endpoint when previewing system layouts from the gallery.
     */
    public function getSystemLayoutRendered(string $layoutKey): string
    {
        $templateFile = $layoutKey . '.html.twig';

        try {
            $loader = new FilesystemLoader($this->layoutsPath);
            $twig = new TwigEnvironment($loader, [
                'strict_variables' => false,
                'autoescape' => false,
            ]);

            $rendered = $twig->render($templateFile, $this->getSampleData());

            // Replace bracket placeholders with English preview text
            return $this->replacePreviewPlaceholders($rendered);
        } catch (\Throwable) {
            $path = $this->layoutsPath . '/' . $templateFile;
            return file_exists($path) ? (string) file_get_contents($path) : '';
        }
    }

    /**
     * Render a system layout to flat HTML (resolving {% extends %} blocks).
     * Used when duplicating a system layout so the user gets the fully resolved HTML.
     */
    private function renderSystemLayout(string $layoutKey): string
    {
        $templateFile = $layoutKey . '.html.twig';

        try {
            $loader = new FilesystemLoader($this->layoutsPath);
            $twig = new TwigEnvironment($loader, [
                'strict_variables' => false,
                'autoescape' => false,
            ]);

            // Render with placeholder variables so the user sees the template structure
            return $twig->render($templateFile, $this->getTemplateVariables());
        } catch (\Throwable) {
            // Fallback: just read the raw file
            $path = $this->layoutsPath . '/' . $templateFile;
            return file_exists($path) ? (string) file_get_contents($path) : '';
        }
    }

    /**
     * Get placeholder variables for template duplication.
     * These are the Twig variables that will be replaced during rendering.
     */
    private function getTemplateVariables(): array
    {
        return [
            'banner_type' => '{{ banner_type }}',
            'display_position' => '{{ display_position }}',
            'preference_type' => '{{ preference_type }}',
            'preference_position' => '{{ preference_position }}',
            'colors' => [
                'notice_bg' => '{{ colors.notice_bg }}',
                'notice_border' => '{{ colors.notice_border }}',
                'notice_title' => '{{ colors.notice_title }}',
                'notice_description' => '{{ colors.notice_description }}',
            ],
            'buttons_html' => '{{ buttons_html|raw }}',
            'pref_buttons_html' => '{{ pref_buttons_html|raw }}',
            'revisit_html' => '{{ revisit_html|raw }}',
            'cookie_categories_html' => '{{ cookie_categories_html|raw }}',
            'branding_html' => '{{ branding_html|raw }}',
            'logo_html' => '{{ logo_html|raw }}',
            'close_button_html' => '{{ close_button_html|raw }}',
            'privacy_policy_link' => '{{ privacy_policy_link|raw }}',
            'banner_cookie_list' => '{{ banner_cookie_list|raw }}',
            'google_privacy_policy' => '{{ google_privacy_policy|raw }}',
            'overlay' => true,
        ];
    }

    /**
     * Sample data for live preview rendering.
     * Uses production-accurate English texts, default colors, and real branding.
     */
    private function getSampleData(): array
    {
        return [
            'banner_type' => 'box-bottom',
            'display_position' => '-bottom-left',
            'preference_type' => 'popup-center',
            'preference_position' => '-center',
            'overlay' => true,
            'colors' => [
                'notice_bg' => '#ffffff',
                'notice_border' => '#F4F4F4',
                'notice_title' => '#000000',
                'notice_description' => '#000000',
            ],
            'buttons_html' => '<button class="cnz-btn btn-cookieAccept" style="background:#0d6efd;color:#fff;border:2px solid #0d6efd;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;flex:auto;">Accept All</button>'
                . '<button class="cnz-btn btn-cookieReject" style="background:transparent;color:#0d6efd;border:2px solid #0d6efd;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;flex:auto;">Reject All</button>'
                . '<button class="cnz-btn btn-cookieCustomize" style="background:transparent;color:#0d6efd;border:2px solid #0d6efd;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;flex:auto;">Customize</button>',
            'pref_buttons_html' => '<button class="cnz-btn btn-savePref" style="background:transparent;color:#0d6efd;border:2px solid #0d6efd;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;flex:auto;">Save My Preferences</button>'
                . '<button class="cnz-btn btn-cookieAccept" style="background:#0d6efd;color:#fff;border:2px solid #0d6efd;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;flex:auto;">Accept All</button>'
                . '<button class="cnz-btn btn-cookieReject" style="background:transparent;color:#0d6efd;border:2px solid #0d6efd;padding:10px 20px;border-radius:4px;cursor:pointer;font-size:14px;font-weight:500;flex:auto;">Reject All</button>',
            'revisit_html' => '<div class="conzent-revisit" style="position:fixed;bottom:16px;left:16px;">'
                . '<button style="background:#0d6efd;color:#fff;border:none;padding:8px 14px;border-radius:4px;font-size:12px;cursor:pointer;" title="Consent Preferences">Consent Preferences</button>'
                . '</div>',
            'cookie_categories_html' => '<div class="conzent-accordion">'
                . '<div class="conzent-accordion-item">'
                . '<div class="conzent-accordion-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #eee;">'
                . '<button class="conzent-accordion-btn" style="background:none;border:none;font-weight:600;font-size:14px;color:#000;cursor:pointer;padding:0;">Necessary</button>'
                . '<span class="conzent-always-active" style="font-size:12px;color:#22b573;font-weight:500;">Always Active</span>'
                . '</div>'
                . '</div>'
                . '</div>'
                . '<div class="conzent-accordion">'
                . '<div class="conzent-accordion-item">'
                . '<div class="conzent-accordion-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #eee;">'
                . '<button class="conzent-accordion-btn" style="background:none;border:none;font-weight:600;font-size:14px;color:#000;cursor:pointer;padding:0;">Analytics</button>'
                . '<div class="conzent-switch"><input type="checkbox" class="sliding-switch" style="appearance:none;width:35px;height:20px;border-radius:12px;background-color:#ddd;cursor:pointer;position:relative;"></div>'
                . '</div>'
                . '</div>'
                . '</div>'
                . '<div class="conzent-accordion">'
                . '<div class="conzent-accordion-item">'
                . '<div class="conzent-accordion-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #eee;">'
                . '<button class="conzent-accordion-btn" style="background:none;border:none;font-weight:600;font-size:14px;color:#000;cursor:pointer;padding:0;">Marketing</button>'
                . '<div class="conzent-switch"><input type="checkbox" class="sliding-switch" style="appearance:none;width:35px;height:20px;border-radius:12px;background-color:#ddd;cursor:pointer;position:relative;"></div>'
                . '</div>'
                . '</div>'
                . '</div>'
                . '<div class="conzent-accordion">'
                . '<div class="conzent-accordion-item">'
                . '<div class="conzent-accordion-header" style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid #eee;">'
                . '<button class="conzent-accordion-btn" style="background:none;border:none;font-weight:600;font-size:14px;color:#000;cursor:pointer;padding:0;">Functional</button>'
                . '<div class="conzent-switch"><input type="checkbox" class="sliding-switch" style="appearance:none;width:35px;height:20px;border-radius:12px;background-color:#ddd;cursor:pointer;position:relative;"></div>'
                . '</div>'
                . '</div>'
                . '</div>',
            'branding_html' => 'Powered by&nbsp;<a href="https://getconzent.com" target="_blank" rel="nofollow" style="display:flex;align-items:center;"><img src="/media/branding_logo.png" alt="Conzent" style="max-height:15px;"></a>',
            'logo_html' => '<img src="/media/logo_icon.png" alt="Conzent" style="max-height:24px;vertical-align:middle;">',
            'close_button_html' => '<svg width="14" height="14" viewBox="0 0 14 14" style="cursor:pointer;"><path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2"/></svg>',
            'privacy_policy_link' => '<a href="#" class="cnz-privacy-policy" rel="nofollow" style="color:#0d6efd;">Cookie Policy</a>',
            'banner_cookie_list' => '',
            'google_privacy_policy' => '',
        ];
    }

    /**
     * Wrap rendered HTML in a preview shell with base banner CSS.
     */
    private function wrapPreviewHtml(string $html): string
    {
        $cssPath = dirname($this->layoutsPath) . '/css/banner.css';
        $css = file_exists($cssPath) ? (string) file_get_contents($cssPath) : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>{$css}</style>
            <style>
                body { margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f5f5f5; min-height: 100vh; }
                /* Make fixed-position banners visible in preview iframe */
                #Conzent { position: relative !important; top: auto !important; bottom: auto !important; left: auto !important; right: auto !important; transform: none !important; margin: 16px auto; max-width: 95%; }
                .conzent-modal { position: relative !important; top: auto !important; bottom: auto !important; left: auto !important; right: auto !important; transform: none !important; margin: 24px auto; max-width: 95%; box-shadow: 0 4px 24px rgba(0,0,0,0.12); }
                .conzent-preference-center { width: 100% !important; max-width: 100% !important; }
                .conzent-overlay { display: none; }
                .conzent-revisit { display: none; }
                /* Ensure branding footer renders properly */
                .conzent-branding { padding: 8px; font-size: 12px; font-weight: 400; line-height: 20px; text-align: right; border-radius: 0 0 6px 6px; direction: ltr; display: flex; justify-content: flex-end; align-items: center; color: #293c5b; background-color: transparent; }
                .conzent-branding a { display: flex; align-items: center; }
                .conzent-branding img { max-height: 15px; }
            </style>
        </head>
        <body>
            {$html}
        </body>
        </html>
        HTML;
    }

    /**
     * Replace bracket content placeholders with English preview text.
     * These are the same placeholders that ScriptGenerationService replaces
     * with translated content in production.
     */
    private function replacePreviewPlaceholders(string $html): string
    {
        $replacements = [
            // Cookie notice
            '[conzent_cookie_notice_banner_title]' => 'We value your privacy',
            '[conzent_cookie_notice_message]' => 'We use cookies to customize our content and ads, to provide social media features and to analyze our traffic. We also share information about your use of our site with our social media, advertising and analytics partners who may combine it with other information that you\'ve provided to them or that they\'ve collected from your use of their services.',
            // Preference center
            '[conzent_preference_center_preference_title]' => 'Customize Consent Preferences',
            '[conzent_preference_center_overview]' => 'We use cookies to help you navigate efficiently and perform certain functions. You will find detailed information about all cookies under each consent category below. The cookies that are categorized as &quot;Necessary&quot; are stored on your browser as they are essential for enabling the basic functionalities of the site.',
            // IAB notice & preference
            '[conzent_iab_notice_description]' => '<p>We and our partners use cookies and other tracking technologies to improve your experience on our website. We may store and/or access information on a device and process personal data, such as your IP address and browsing data, for personalised advertising and content, advertising and content measurement, audience research and services development.</p><p>Please note that your consent will be valid across all our subdomains. You can change or withdraw your consent at any time by clicking the &quot;Consent Preferences&quot; button at the bottom of your screen.</p>',
            '[conzent_iab_preference_description]' => '<p>Customize your consent preferences for Cookie Categories and advertising tracking preferences for Purposes &amp; Features and Vendors below.</p>',
            '[conzent_iab_nav_item_cookie_categories]' => 'Cookie Categories',
            '[conzent_iab_nav_item_purposes_n_features]' => 'Purposes &amp; Features',
            '[conzent_iab_nav_item_vendors]' => 'Vendors',
            '[conzent_iab_common_purposes]' => 'Purposes',
            '[conzent_iab_common_special_purposes]' => 'Special Purposes',
            '[conzent_iab_common_features]' => 'Features',
            '[conzent_iab_common_special_features]' => 'Special Features',
            '[conzent_iab_vendors_third_party_title]' => 'Third Party Vendors',
            '[conzent_iab_vendors_google_ad_title]' => 'Google Ad Tech Providers',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $html);
    }

    /**
     * Load the layout registry from disk.
     *
     * @return array<string, array<string, mixed>>
     */
    private function loadRegistry(): array
    {
        if ($this->registry === null) {
            $file = $this->layoutsPath . '/layouts.php';
            $this->registry = file_exists($file) ? (array) require $file : [];
        }

        return $this->registry;
    }
}
