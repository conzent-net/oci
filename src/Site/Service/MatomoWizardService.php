<?php

declare(strict_types=1);

namespace OCI\Site\Service;

/**
 * Matomo TM Wizard — orchestrates tag/trigger/variable creation
 * in a Matomo Tag Manager container.
 *
 * Mirrors GtmWizardService but targets the Matomo TagManager API.
 * All tags use CustomHtml type (free Matomo TM).
 */
final class MatomoWizardService
{
    public function __construct(
        private readonly MatomoApiService $matomoApi,
    ) {}

    /**
     * Create the foundation: Conzent Trigger, Conzent Cookie variable,
     * and Conzent CMP tag.
     *
     * @return array{success: bool, error?: string}
     */
    public function createFoundation(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $websiteKey,
        string $serverUrl = '',
    ): array {
        // 1. Create "Conzent Consent Update" trigger (CustomEvent type)
        $trigger = $this->matomoApi->createTrigger(
            $matomoUrl,
            $tokenAuth,
            $idSite,
            $idContainer,
            'CustomEvent',
            'Conzent Consent Update',
            ['eventName' => 'conzent_consent_update'],
        );

        if ($trigger === null || isset($trigger['result']) && $trigger['result'] === 'error') {
            // May already exist — try to find it
            $triggerId = $this->findConzentTriggerId($matomoUrl, $tokenAuth, $idSite, $idContainer);
            if ($triggerId === null) {
                $errorMsg = $trigger['message'] ?? 'Failed to create Conzent trigger';
                return ['success' => false, 'error' => $errorMsg];
            }
        }
        usleep(100_000);

        // 2. Create "Conzent Cookie" variable (CookieName type)
        $variable = $this->matomoApi->createVariable(
            $matomoUrl,
            $tokenAuth,
            $idSite,
            $idContainer,
            'CookieName',
            'Conzent Cookie',
            ['cookieName' => 'conzentConsentPrefs'],
        );
        // Variable creation failure is non-fatal (may already exist)
        usleep(100_000);

        // 3. Create "Conzent CMP" tag using the ConzentCmp plugin template
        //    Priority 1 ensures it fires before all other tags.
        //    Falls back to CustomHtml if the plugin is not installed.
        $effectiveServerUrl = $serverUrl !== '' ? rtrim($serverUrl, '/') : 'https://app.getconzent.com';

        // Find the built-in PageView trigger
        $pageViewTriggerId = $this->findPageViewTriggerId($matomoUrl, $tokenAuth, $idSite, $idContainer);

        // Try native ConzentCmp tag type first (requires plugin installed)
        $tag = $this->matomoApi->createTag(
            $matomoUrl,
            $tokenAuth,
            $idSite,
            $idContainer,
            'ConzentCmp',
            'Conzent CMP',
            ['websiteKey' => $websiteKey, 'serverUrl' => $effectiveServerUrl],
            $pageViewTriggerId !== null ? [$pageViewTriggerId] : [],
            [],
            1,
        );

        // Fall back to CustomHtml if ConzentCmp plugin not available
        if ($tag === null || (isset($tag['result']) && $tag['result'] === 'error')) {
            $conzentScript = '<script src="' . htmlspecialchars($effectiveServerUrl, \ENT_QUOTES, 'UTF-8')
                . '/sites_data/' . htmlspecialchars($websiteKey, \ENT_QUOTES, 'UTF-8')
                . '/script.js" async></script>';

            $tag = $this->matomoApi->createTag(
                $matomoUrl,
                $tokenAuth,
                $idSite,
                $idContainer,
                'CustomHtml',
                'Conzent CMP',
                ['customHtml' => $conzentScript],
                $pageViewTriggerId !== null ? [$pageViewTriggerId] : [],
                [],
                1,
            );
        }

        if ($tag === null || (isset($tag['result']) && $tag['result'] === 'error')) {
            $errorMsg = $tag['message'] ?? 'Failed to create Conzent CMP tag (may already exist)';
            return ['success' => false, 'error' => $errorMsg];
        }

        return ['success' => true];
    }

    /**
     * Create a Google Analytics tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createGoogleAnalytics(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $script,
    ): array {
        return $this->createPixelTag($matomoUrl, $tokenAuth, $idSite, $idContainer, 'Google Analytics', $script);
    }

    /**
     * Create a Facebook Pixel tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createFacebookPixel(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $script,
    ): array {
        return $this->createPixelTag($matomoUrl, $tokenAuth, $idSite, $idContainer, 'Facebook Pixel', $script);
    }

    /**
     * Create a Microsoft Clarity tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createMicrosoftClarity(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $script,
    ): array {
        return $this->createPixelTag($matomoUrl, $tokenAuth, $idSite, $idContainer, 'Microsoft Clarity', $script);
    }

    /**
     * Create a LinkedIn Insight tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createLinkedInInsight(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $script,
    ): array {
        return $this->createPixelTag($matomoUrl, $tokenAuth, $idSite, $idContainer, 'LinkedIn Insight Tag', $script);
    }

    /**
     * Create a Snapchat Pixel tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createSnapchatPixel(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $script,
    ): array {
        return $this->createPixelTag($matomoUrl, $tokenAuth, $idSite, $idContainer, 'Snapchat Pixel', $script);
    }

    /**
     * Create a TikTok Pixel tag (Custom HTML).
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createTiktokPixel(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $script,
    ): array {
        return $this->createPixelTag($matomoUrl, $tokenAuth, $idSite, $idContainer, 'TikTok Pixel', $script);
    }

    /**
     * Create a generic custom HTML tag.
     *
     * @return array{success: bool, name: string, error?: string}
     */
    public function createCustomTag(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $name,
        string $script,
    ): array {
        return $this->createPixelTag($matomoUrl, $tokenAuth, $idSite, $idContainer, $name, $script);
    }

    // ─── Private helpers ─────────────────────────────────────

    /**
     * Create a Custom HTML tag that fires on the Conzent consent trigger.
     *
     * @return array{success: bool, name: string, error?: string}
     */
    private function createPixelTag(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
        string $name,
        string $html,
    ): array {
        $triggerId = $this->findConzentTriggerId($matomoUrl, $tokenAuth, $idSite, $idContainer);

        // Fall back to PageView trigger if Conzent trigger not found
        if ($triggerId === null) {
            $triggerId = $this->findPageViewTriggerId($matomoUrl, $tokenAuth, $idSite, $idContainer);
        }

        $tag = $this->matomoApi->createTag(
            $matomoUrl,
            $tokenAuth,
            $idSite,
            $idContainer,
            'CustomHtml',
            $name,
            ['customHtml' => $html],
            $triggerId !== null ? [$triggerId] : [],
        );

        if ($tag === null || (isset($tag['result']) && $tag['result'] === 'error')) {
            $errorMsg = $tag['message'] ?? 'Failed to create tag (may already exist)';
            return ['success' => false, 'name' => $name, 'error' => $errorMsg];
        }

        return ['success' => true, 'name' => $name];
    }

    /**
     * Find the "Conzent Consent Update" trigger ID in the container.
     */
    private function findConzentTriggerId(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
    ): ?string {
        $triggers = $this->matomoApi->listTriggers($matomoUrl, $tokenAuth, $idSite, $idContainer);
        foreach ($triggers as $t) {
            if (($t['name'] ?? '') === 'Conzent Consent Update') {
                return (string) ($t['idtrigger'] ?? '');
            }
        }

        return null;
    }

    /**
     * Find the built-in "Pageview" trigger ID.
     */
    private function findPageViewTriggerId(
        string $matomoUrl,
        string $tokenAuth,
        int $idSite,
        string $idContainer,
    ): ?string {
        $triggers = $this->matomoApi->listTriggers($matomoUrl, $tokenAuth, $idSite, $idContainer);
        foreach ($triggers as $t) {
            $name = $t['name'] ?? '';
            $type = $t['type'] ?? '';
            if ($type === 'PageView' || stripos($name, 'pageview') !== false || stripos($name, 'page view') !== false) {
                return (string) ($t['idtrigger'] ?? '');
            }
        }

        return null;
    }
}
