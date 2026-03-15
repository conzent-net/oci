<?php

declare(strict_types=1);

namespace OCI\Site\Service;

/**
 * GTM Wizard — orchestrates tag/trigger/variable creation in a GTM workspace.
 *
 * Ports the legacy TagManagerConfig class (tagmanagerAPI.php) to a stateless
 * service that delegates all API calls to GtmOAuthService (raw cURL).
 */
final class GtmWizardService
{
    /** Built-in trigger IDs used by GTM */
    private const TRIGGER_ALL_PAGES = '2147479553';
    private const TRIGGER_CONSENT_INIT = '2147479572';

    /** Built-in variable types */
    private const BUILT_IN_VARS = [
        'clickClasses',
        'clickElement',
        'clickId',
        'clickTarget',
        'clickUrl',
        'clickText',
    ];

    /** Conzent CMP community template gallery reference */
    private const CONZENT_GALLERY_REF = [
        'host' => 'github.com',
        'owner' => 'conzent-net',
        'repository' => 'conzent_cmp',
        'version' => '3d0e81958ae9236677a39420363a9497153310ee',
        'signature' => 'a99693d6fe642740cd4954b5930593c0dc1a58998cd0cc3f439b8e41bcc00395',
    ];

    /** LinkedIn community template gallery reference */
    private const LINKEDIN_GALLERY_REF = [
        'host' => 'github.com',
        'owner' => 'linkedin',
        'repository' => 'linkedin-gtm-community-template',
        'version' => 'c07099c0e0cf0ade2057ee4016d3da9f32959169',
        'signature' => '73bf4e41e6569084d9c1a9fdb1d8de96422e98936c405752e26a316c0040440e',
    ];

    public function __construct(
        private readonly GtmOAuthService $gtmApi,
    ) {}

    /**
     * Create the foundation: built-in variables, Conzent Cookie variable,
     * Conzent Trigger, Conzent CMP template + tag.
     *
     * @return array{success: bool, error?: string}
     */
    public function createFoundation(string $token, string $wsPath, string $websiteKey): array
    {
        // 1. Create built-in variables (click tracking)
        foreach (self::BUILT_IN_VARS as $type) {
            $this->gtmApi->createBuiltInVariable($token, $wsPath, $type);
            usleep(50_000);
        }

        // 2. Create "Conzent Cookie" first-party cookie variable
        $cookieVar = [
            'name' => 'Conzent Cookie',
            'type' => 'k', // first-party cookie
            'parameter' => [
                [
                    'key' => 'name',
                    'type' => 'template',
                    'value' => 'conzentConsentPrefs',
                ],
            ],
        ];
        $this->gtmApi->createVariable($token, $wsPath, $cookieVar);
        usleep(50_000);

        // 3. Create "Conzent Trigger" custom event trigger
        $trigger = [
            'name' => 'Conzent Trigger',
            'type' => 'customEvent',
            'customEventFilter' => [
                [
                    'type' => 'equals',
                    'parameter' => [
                        ['key' => 'arg0', 'type' => 'template', 'value' => '{{_event}}'],
                        ['key' => 'arg1', 'type' => 'template', 'value' => 'conzent_consent_update'],
                    ],
                ],
            ],
        ];
        $this->gtmApi->createTrigger($token, $wsPath, $trigger);
        usleep(50_000);

        // 4. Install Conzent CMP community template
        $existingTemplate = $this->findTemplate($token, $wsPath, 'Conzent CMP');
        if ($existingTemplate === null) {
            $templateData = $this->loadTemplateFile('conzent.tpl');
            if ($templateData === null) {
                return ['success' => false, 'error' => 'Could not load Conzent CMP template file'];
            }

            $template = [
                'name' => 'Conzent CMP',
                'templateData' => $templateData,
                'galleryReference' => self::CONZENT_GALLERY_REF,
            ];
            $result = $this->gtmApi->createTemplate($token, $wsPath, $template);
            usleep(50_000);

            // Re-fetch to get containerId and templateId
            $existingTemplate = $this->findTemplate($token, $wsPath, 'Conzent CMP');
            if ($existingTemplate === null) {
                return ['success' => false, 'error' => 'Failed to create Conzent CMP template'];
            }
        }

        $containerId = (string) ($existingTemplate['containerId'] ?? '');
        $templateId = (string) ($existingTemplate['templateId'] ?? '');

        // 5. Create "Conzent CMP" tag on Consent Initialization - All Pages
        $tag = [
            'name' => 'Conzent CMP',
            'type' => 'cvt_' . $containerId . '_' . $templateId,
            'tagFiringOption' => 'oncePerLoad',
            'priority' => ['key' => 'priority', 'type' => 'integer', 'value' => '1'],
            'firingTriggerId' => [self::TRIGGER_CONSENT_INIT],
            'parameter' => [
                ['key' => 'websiteid', 'type' => 'template', 'value' => $websiteKey],
                ['key' => 'urlPassThrough', 'type' => 'boolean', 'value' => 'true'],
                ['key' => 'adsRedaction', 'type' => 'boolean', 'value' => 'true'],
                ['key' => 'waitForTime', 'type' => 'template', 'value' => '500'],
                [
                    'key' => 'regionSettings',
                    'type' => 'list',
                    'list' => [
                        [
                            'type' => 'map',
                            'map' => [
                                ['key' => 'analytics', 'type' => 'template', 'value' => 'denied'],
                                ['key' => 'advertisement', 'type' => 'template', 'value' => 'denied'],
                                ['key' => 'functional', 'type' => 'template', 'value' => 'denied'],
                                ['key' => 'security', 'type' => 'template', 'value' => 'granted'],
                                ['key' => 'adUserData', 'type' => 'template', 'value' => 'denied'],
                                ['key' => 'adPersonal', 'type' => 'template', 'value' => 'denied'],
                                ['key' => 'regions', 'type' => 'template', 'value' => 'All'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $result = $this->gtmApi->createTag($token, $wsPath, $tag);
        if ($result === null) {
            return ['success' => false, 'error' => 'Failed to create Conzent CMP tag (may already exist)'];
        }

        return ['success' => true];
    }

    /**
     * Create a Google Analytics (GA4) tag.
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createGoogleAnalytics(string $token, string $wsPath, string $measurementId): array
    {
        $name = 'Google Analytics';
        $varName = 'Google Measurement Id';

        // Create constant variable for measurement ID
        $this->gtmApi->createVariable($token, $wsPath, [
            'name' => $varName,
            'type' => 'c',
            'parameter' => [['key' => 'value', 'type' => 'template', 'value' => $measurementId]],
        ]);
        usleep(50_000);

        $triggerId = $this->findConzentTriggerId($token, $wsPath);

        $tag = [
            'name' => 'Google Tag',
            'type' => 'googtag',
            'tagFiringOption' => 'oncePerEvent',
            'firingTriggerId' => [$triggerId ?? self::TRIGGER_ALL_PAGES],
            'consentSettings' => [
                'consentStatus' => 'needed',
                'consentType' => ['type' => 'list', 'list' => [['type' => 'template', 'value' => 'analytics_storage']]],
            ],
            'parameter' => [
                ['key' => 'tagId', 'type' => 'template', 'value' => '{{' . $varName . '}}'],
            ],
        ];

        $result = $this->gtmApi->createTag($token, $wsPath, $tag);

        return $result !== null
            ? ['success' => true, 'name' => $name]
            : ['success' => false, 'name' => $name, 'error' => 'Failed to create tag (may already exist)'];
    }

    /**
     * Create Google Ads conversion tracking + conversion linker tags.
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createGoogleAds(string $token, string $wsPath, string $conversionId, string $conversionLabel): array
    {
        $name = 'Google Ads';

        // Create variables
        $this->gtmApi->createVariable($token, $wsPath, [
            'name' => 'Conversion Id',
            'type' => 'c',
            'parameter' => [['key' => 'value', 'type' => 'template', 'value' => $conversionId]],
        ]);
        usleep(50_000);

        $this->gtmApi->createVariable($token, $wsPath, [
            'name' => 'Conversion Label',
            'type' => 'c',
            'parameter' => [['key' => 'value', 'type' => 'template', 'value' => $conversionLabel]],
        ]);
        usleep(50_000);

        $triggerId = $this->findConzentTriggerId($token, $wsPath);

        // Conversion Linker on All Pages
        $linkerTag = [
            'name' => 'Google Ads Conversion Linker',
            'type' => 'gclidw',
            'tagFiringOption' => 'oncePerEvent',
            'firingTriggerId' => [self::TRIGGER_ALL_PAGES],
        ];
        $this->gtmApi->createTag($token, $wsPath, $linkerTag);
        usleep(50_000);

        // Conversion Tracking tag
        $convTag = [
            'name' => 'Google Ads Conversion Tracking',
            'type' => 'awct',
            'tagFiringOption' => 'oncePerEvent',
            'firingTriggerId' => [$triggerId ?? self::TRIGGER_ALL_PAGES],
            'consentSettings' => [
                'consentStatus' => 'needed',
                'consentType' => ['type' => 'list', 'list' => [['type' => 'template', 'value' => 'ad_storage']]],
            ],
            'parameter' => [
                ['key' => 'enableNewCustomerReporting', 'type' => 'boolean', 'value' => 'false'],
                ['key' => 'enableConversionLinker', 'type' => 'boolean', 'value' => 'true'],
                ['key' => 'enableProductReporting', 'type' => 'boolean', 'value' => 'true'],
                ['key' => 'enableEnhancedConversion', 'type' => 'boolean', 'value' => 'false'],
                ['key' => 'enableShippingData', 'type' => 'boolean', 'value' => 'false'],
                ['key' => 'conversionId', 'type' => 'template', 'value' => '{{Conversion Id}}'],
                ['key' => 'conversionLabel', 'type' => 'template', 'value' => '{{Conversion Label}}'],
                ['key' => 'rdp', 'type' => 'boolean', 'value' => 'false'],
            ],
        ];
        $result = $this->gtmApi->createTag($token, $wsPath, $convTag);

        return $result !== null
            ? ['success' => true, 'name' => $name]
            : ['success' => false, 'name' => $name, 'error' => 'Failed to create tag (may already exist)'];
    }

    /**
     * Create a Facebook Pixel tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createFacebookPixel(string $token, string $wsPath, string $html): array
    {
        return $this->createCustomHtmlTag($token, $wsPath, 'Facebook Pixel', $html, 'ad_storage');
    }

    /**
     * Create a Microsoft Clarity tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createMicrosoftClarity(string $token, string $wsPath, string $html): array
    {
        return $this->createCustomHtmlTag($token, $wsPath, 'Microsoft Clarity', $html, 'analytics_storage');
    }

    /**
     * Create a Snapchat Pixel tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createSnapchatPixel(string $token, string $wsPath, string $html): array
    {
        return $this->createCustomHtmlTag($token, $wsPath, 'SnapChat Pixel', $html, 'ad_storage');
    }

    /**
     * Create a TikTok Pixel tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createTiktokPixel(string $token, string $wsPath, string $html): array
    {
        return $this->createCustomHtmlTag($token, $wsPath, 'TikTok Pixel', $html, 'ad_storage');
    }

    /**
     * Create a LinkedIn Insight tag (community template).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createLinkedInInsight(string $token, string $wsPath, string $partnerId): array
    {
        $name = 'LinkedIn Insight Tag';
        $varName = 'LinkedIn Insight Id';

        // Create variable
        $this->gtmApi->createVariable($token, $wsPath, [
            'name' => $varName,
            'type' => 'c',
            'parameter' => [['key' => 'value', 'type' => 'template', 'value' => $partnerId]],
        ]);
        usleep(50_000);

        // Install LinkedIn template if not present
        $existing = $this->findTemplate($token, $wsPath, 'LinkedIn InsightTag 2.0');
        if ($existing === null) {
            $templateData = $this->loadTemplateFile('linkedIn.tpl');
            if ($templateData !== null) {
                $this->gtmApi->createTemplate($token, $wsPath, [
                    'name' => 'LinkedIn Insight Tag',
                    'templateData' => $templateData,
                    'galleryReference' => self::LINKEDIN_GALLERY_REF,
                ]);
                usleep(50_000);
                $existing = $this->findTemplate($token, $wsPath, 'LinkedIn InsightTag 2.0');
            }
        }

        if ($existing === null) {
            return ['success' => false, 'name' => $name, 'error' => 'Could not install LinkedIn template'];
        }

        $containerId = (string) ($existing['containerId'] ?? '');
        $templateId = (string) ($existing['templateId'] ?? '');
        $triggerId = $this->findConzentTriggerId($token, $wsPath);

        $tag = [
            'name' => $name,
            'type' => 'cvt_' . $containerId . '_' . $templateId,
            'tagFiringOption' => 'oncePerEvent',
            'firingTriggerId' => [$triggerId ?? self::TRIGGER_ALL_PAGES],
            'consentSettings' => [
                'consentStatus' => 'needed',
                'consentType' => ['type' => 'list', 'list' => [['type' => 'template', 'value' => 'ad_storage']]],
            ],
            'parameter' => [
                ['key' => 'customUrl', 'type' => 'template'],
                ['key' => 'eventId', 'type' => 'template'],
                ['key' => 'partnerId', 'type' => 'template', 'value' => '{{' . $varName . '}}'],
                ['key' => 'conversionId', 'type' => 'template'],
            ],
        ];

        $result = $this->gtmApi->createTag($token, $wsPath, $tag);

        return $result !== null
            ? ['success' => true, 'name' => $name]
            : ['success' => false, 'name' => $name, 'error' => 'Failed to create tag (may already exist)'];
    }

    /**
     * Create a Pinterest Tag.
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createPinterestPixel(string $token, string $wsPath, string $tagId): array
    {
        $name = 'Pinterest Tag';
        $varName = 'Pinterest Pixel Id';

        $this->gtmApi->createVariable($token, $wsPath, [
            'name' => $varName,
            'type' => 'c',
            'parameter' => [['key' => 'value', 'type' => 'template', 'value' => $tagId]],
        ]);
        usleep(50_000);

        $triggerId = $this->findConzentTriggerId($token, $wsPath);

        $tag = [
            'name' => $name,
            'type' => 'pntr',
            'firingTriggerId' => [$triggerId ?? self::TRIGGER_ALL_PAGES],
            'consentSettings' => [
                'consentStatus' => 'needed',
                'consentType' => ['type' => 'list', 'list' => [['type' => 'template', 'value' => 'ad_storage']]],
            ],
            'parameter' => [
                ['key' => 'tagId', 'type' => 'template', 'value' => '{{' . $varName . '}}'],
                ['key' => 'eventName', 'type' => 'template'],
                ['key' => 'setOptOut', 'type' => 'boolean', 'value' => 'false'],
            ],
        ];

        $result = $this->gtmApi->createTag($token, $wsPath, $tag);

        return $result !== null
            ? ['success' => true, 'name' => $name]
            : ['success' => false, 'name' => $name, 'error' => 'Failed to create tag (may already exist)'];
    }

    // ─── Private helpers ─────────────────────────────────────

    /**
     * Create a Custom HTML tag with consent requirements.
     *
     * @return array{success: bool, name: string, error?: string}
     */
    private function createCustomHtmlTag(string $token, string $wsPath, string $name, string $html, string $consentType): array
    {
        $triggerId = $this->findConzentTriggerId($token, $wsPath);

        $tag = [
            'name' => $name,
            'type' => 'html',
            'tagFiringOption' => 'oncePerEvent',
            'firingTriggerId' => [$triggerId ?? self::TRIGGER_ALL_PAGES],
            'consentSettings' => [
                'consentStatus' => 'needed',
                'consentType' => ['type' => 'list', 'list' => [['type' => 'template', 'value' => $consentType]]],
            ],
            'parameter' => [
                ['key' => 'html', 'type' => 'template', 'value' => $html],
                ['key' => 'supportDocumentWrite', 'type' => 'boolean', 'value' => 'false'],
            ],
        ];

        $result = $this->gtmApi->createTag($token, $wsPath, $tag);

        return $result !== null
            ? ['success' => true, 'name' => $name]
            : ['success' => false, 'name' => $name, 'error' => 'Failed to create tag (may already exist)'];
    }

    /**
     * Find the "Conzent Trigger" trigger ID in the workspace.
     */
    private function findConzentTriggerId(string $token, string $wsPath): ?string
    {
        $triggers = $this->gtmApi->listTriggers($token, $wsPath);
        foreach ($triggers as $t) {
            if (($t['name'] ?? '') === 'Conzent Trigger') {
                return (string) ($t['triggerId'] ?? '');
            }
        }

        return null;
    }

    /**
     * Find a custom template by name.
     *
     * @return array<string, mixed>|null
     */
    private function findTemplate(string $token, string $wsPath, string $name): ?array
    {
        $templates = $this->gtmApi->listTemplates($token, $wsPath);
        foreach ($templates as $tpl) {
            if (($tpl['name'] ?? '') === $name) {
                return $tpl;
            }
        }

        return null;
    }

    /**
     * Load a GTM template file from the legacy assets directory.
     */
    private function loadTemplateFile(string $filename): ?string
    {
        // Try OCI public path first, then legacy path
        $paths = [
            \dirname(__DIR__, 3) . '/public/assets/gtm/templates/' . $filename,
            \dirname(__DIR__, 3) . '/legacy/app/assets/gtm/templates/' . $filename,
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                $content = file_get_contents($path);
                return $content !== false ? $content : null;
            }
        }

        return null;
    }
}
