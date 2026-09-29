<?php

declare(strict_types=1);

namespace OCI\Policy\Service;

use Doctrine\DBAL\Connection;
use OCI\Policy\Repository\PolicyRepositoryInterface;

/**
 * Cookie & privacy policy generation, wizard step management, template application.
 *
 * Port of legacy action.php (lines 5879-6895) and custom_functions.php policy_fields().
 */
final class PolicyService
{
    private string $resourceDir;

    public function __construct(
        private readonly PolicyRepositoryInterface $policyRepo,
        private readonly Connection $db,
    ) {
        $this->resourceDir = \dirname(__DIR__, 3) . '/resources/policy';
    }

    // ── Cookie Policy ───────────────────────────────────────

    /**
     * Get or auto-create a cookie policy for a site.
     */
    public function getOrCreateCookiePolicy(int $siteId, int $languageId): array
    {
        $existing = $this->policyRepo->getCookiePolicy($siteId, $languageId);
        if ($existing !== null) {
            return $existing;
        }

        $prefDescription = @file_get_contents($this->resourceDir . '/pref_description.html') ?: '';

        $data = [
            'heading' => 'Cookie Policy',
            'type_heading' => 'Types of Cookies we use',
            'preference_heading' => 'Manage cookie preferences',
            'preference_description' => $prefDescription,
            'revisit_consent_widget' => '<a class="conzent-revisit">Cookie Settings</a>',
            'effective_date' => date('Y-m-d'),
            'show_audit_table' => 1,
            'url_key' => bin2hex(random_bytes(16)),
        ];

        $id = $this->policyRepo->saveCookiePolicy($siteId, $languageId, $data);

        return $this->policyRepo->getCookiePolicy($siteId, $languageId) ?? array_merge($data, ['id' => $id]);
    }

    /**
     * Save data for a specific cookie policy wizard step.
     */
    public function saveCookiePolicyStep(int $siteId, int $languageId, string $step, array $input): array
    {
        $policy = $this->getOrCreateCookiePolicy($siteId, $languageId);

        if ($step === 'cookie_types') {
            $data = [
                'type_heading' => $input['type_heading'] ?? 'Types of Cookies we use',
                'show_audit_table' => !empty($input['show_audit_table']) ? 1 : 0,
                'show_heading' => !empty($input['show_heading']) ? 1 : 0,
            ];
            $this->policyRepo->saveCookiePolicy($siteId, $languageId, $data);
        } elseif ($step === 'preference') {
            $data = [
                'preference_heading' => $input['preference_heading'] ?? 'Manage cookie preferences',
                'preference_description' => $input['preference_description'] ?? '',
                'effective_date' => $input['effective_date'] ?? date('Y-m-d'),
            ];
            $this->policyRepo->saveCookiePolicy($siteId, $languageId, $data);
        }

        if ($step === 'preview' || $step === 'finish') {
            return $this->generateAndSaveCookiePolicy($siteId, $languageId);
        }

        return $this->policyRepo->getCookiePolicy($siteId, $languageId) ?? $policy;
    }

    /**
     * Generate cookie policy HTML from template + data and persist.
     */
    public function generateAndSaveCookiePolicy(int $siteId, int $languageId): array
    {
        $policy = $this->policyRepo->getCookiePolicy($siteId, $languageId);
        if ($policy === null) {
            return [];
        }

        $html = $this->generateCookiePolicyHtml($policy);
        $this->policyRepo->saveCookiePolicy($siteId, $languageId, ['policy_content' => $html]);

        $policy['policy_content'] = $html;
        return $policy;
    }

    /**
     * Build cookie policy HTML by replacing placeholders in the template.
     */
    public function generateCookiePolicyHtml(array $policy): string
    {
        $template = @file_get_contents($this->resourceDir . '/cookie_policy.html') ?: '';

        // Remove heading if show_heading is disabled (for CMS embedding)
        if (($policy['show_heading'] ?? 1) == 0) {
            $template = preg_replace('/<h1[^>]*>\[POLICY_HEADING\]<\/h1>/', '', $template);
        }

        $auditTable = '';
        $typeHeading = '';
        if (($policy['show_audit_table'] ?? 0) == 1) {
            $auditTable = '<div class="cnz-cookie-table"></div>';
            $typeHeading = '<h5>' . htmlspecialchars($policy['type_heading'] ?? '') . '</h5>';
        }

        $effectiveDate = '';
        if (!empty($policy['effective_date'])) {
            $effectiveDate = date('d-M-Y', strtotime($policy['effective_date']));
        }
        $updatedAt = date('d-M-Y');
        if (!empty($policy['updated_at'])) {
            $updatedAt = date('d-M-Y', strtotime($policy['updated_at']));
        }

        $replacements = [
            '[POLICY_HEADING]' => $policy['heading'] ?? 'Cookie Policy',
            '[COOKIE_TYPE_HEADING]' => $typeHeading,
            '[AUDIT_TABLE]' => $auditTable,
            '[PREFERENCE_HEADING]' => $policy['preference_heading'] ?? '',
            '[PREFERENCE_DESCRIPTION]' => $policy['preference_description'] ?? '',
            '[LAST_UPDATED_ON]' => $updatedAt,
            '[EFFECTIVE_DATE]' => $effectiveDate,
            '[COOKIE_SETTING]' => $policy['revisit_consent_widget'] ?? '',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    // ── Privacy Policy ──────────────────────────────────────

    /**
     * Get or auto-create a privacy policy for a site.
     */
    public function getOrCreatePrivacyPolicy(int $siteId, int $languageId): array
    {
        $existing = $this->policyRepo->getPrivacyPolicy($siteId, $languageId);
        if ($existing !== null) {
            if (\is_string($existing['step_data'])) {
                $existing['step_data_decoded'] = json_decode($existing['step_data'], true) ?: $this->getDefaultStepData();
            } else {
                $existing['step_data_decoded'] = $this->getDefaultStepData();
            }
            return $existing;
        }

        $stepData = $this->getDefaultStepData();
        $data = [
            'heading' => 'Privacy Policy',
            'url_key' => bin2hex(random_bytes(16)),
            'step_data' => json_encode($stepData),
            'effective_date' => date('Y-m-d'),
        ];

        $id = $this->policyRepo->savePrivacyPolicy($siteId, $languageId, $data);
        $policy = $this->policyRepo->getPrivacyPolicy($siteId, $languageId) ?? array_merge($data, ['id' => $id]);
        $policy['step_data_decoded'] = $stepData;

        return $policy;
    }

    /**
     * Save data for a specific privacy policy wizard step.
     */
    public function savePrivacyPolicyStep(int $siteId, int $languageId, string $step, array $input): array
    {
        $policy = $this->getOrCreatePrivacyPolicy($siteId, $languageId);
        $stepData = $policy['step_data_decoded'] ?? $this->getDefaultStepData();

        $validSteps = ['website_info', 'data_collection', 'disclosure', 'tracking_tech', 'data_protection'];
        if (\in_array($step, $validSteps, true)) {
            $normalised = $this->normaliseStepData($step, $input);
            $stepData[$step] = $normalised;

            $saveData = ['step_data' => json_encode($stepData)];

            if ($step === 'data_protection') {
                if (!empty($normalised['effective_date'])) {
                    $saveData['effective_date'] = $normalised['effective_date'];
                }
                $saveData['show_heading'] = !empty($input['show_heading']) ? 1 : 0;
            }

            $this->policyRepo->savePrivacyPolicy($siteId, $languageId, $saveData);
        }

        if ($step === 'preview' || $step === 'finish') {
            return $this->generateAndSavePrivacyPolicy($siteId, $languageId);
        }

        return $this->getOrCreatePrivacyPolicy($siteId, $languageId);
    }

    /**
     * Generate privacy policy HTML from template + step_data and persist.
     */
    public function generateAndSavePrivacyPolicy(int $siteId, int $languageId): array
    {
        $policy = $this->policyRepo->getPrivacyPolicy($siteId, $languageId);
        if ($policy === null) {
            return [];
        }

        $stepData = json_decode($policy['step_data'] ?? '{}', true) ?: $this->getDefaultStepData();
        $html = $this->generatePrivacyPolicyHtml($policy, $stepData);

        $this->policyRepo->savePrivacyPolicy($siteId, $languageId, ['policy_content' => $html]);

        $policy['policy_content'] = $html;
        $policy['step_data_decoded'] = $stepData;
        return $policy;
    }

    /**
     * Build privacy policy HTML by replacing placeholders.
     * Direct port of legacy action.php lines 5945-6124.
     */
    public function generatePrivacyPolicyHtml(array $policy, array $stepData): string
    {
        $template = @file_get_contents($this->resourceDir . '/privacy_policy.html') ?: '';

        // Remove heading if show_heading is disabled (for CMS embedding)
        if (($policy['show_heading'] ?? 1) == 0) {
            $template = preg_replace('/<h1[^>]*class="privacy-policy-h1"[^>]*>.*?<\/h1>/s', '', $template);
        }

        $websiteInfo = $stepData['website_info'] ?? [];
        $dataCollection = $stepData['data_collection'] ?? [];
        $disclosure = $stepData['disclosure'] ?? [];
        $trackingTech = $stepData['tracking_tech'] ?? [];

        // ── Collect Information section ──
        $collectInformation = '';
        if (($dataCollection['personal_info'] ?? 'No') === 'Yes') {
            $items = '';
            $personalFields = [
                'name' => 'Name',
                'email' => 'Email',
                'mobile' => 'Mobile',
                'sm_profile' => 'Social Media Profile',
                'dob' => 'Date of birth',
                'address' => 'Residential address',
                'work_address' => 'Work address',
                'payment_info' => 'Payment information',
            ];
            foreach ($personalFields as $key => $label) {
                if (($dataCollection[$key] ?? 'No') === 'Yes') {
                    $items .= '<li>' . $label . '</li>';
                }
            }
            $other = $dataCollection['other_personal_info'] ?? '';
            if ($other !== '') {
                $items .= '<li>' . htmlspecialchars(ucwords($other)) . '</li>';
            }
            $collectTpl = @file_get_contents($this->resourceDir . '/collect_data.html') ?: '';
            $collectInformation = '<li>' . str_replace('[ITEMS]', $items, $collectTpl) . '</li>';
        }

        // ── How We Use section ──
        $useItems = '';
        $usePurposes = [
            'marketing_promotional' => 'Marketing/Promotional',
            'creating_user_account' => 'Creating user accounts',
            'testimonals' => 'Testimonials',
            'feedback_collection' => 'Customer feedback collection',
            'enforce_tc' => 'Enforce T&amp;C',
            'processing_payment' => 'Processing payment',
            'support' => 'Support',
            'administration_info' => 'Administration info',
            'targeted_advertising' => 'Targeted advertising',
            'manage_customer_order' => 'Manage customer order',
            'site_protection' => 'Site protection',
            'user_to_user_comments' => 'User to user comments',
            'dispute_resolution' => 'Dispute resolution',
            'manage_user_account' => 'Manage user account',
        ];
        foreach ($usePurposes as $key => $label) {
            if (($disclosure[$key] ?? 'No') === 'Yes') {
                $useItems .= '<li>' . $label . '</li>';
            }
        }
        $howWeUseTpl = @file_get_contents($this->resourceDir . '/how_we_use.html') ?: '';
        $howWeUse = '<li>' . str_replace('[ITEMS]', $useItems, $howWeUseTpl) . '</li>';

        // ── How We Collect section ──
        $howWeCollect = '';
        if (($dataCollection['newsletters_optout'] ?? 'No') === 'Yes') {
            $items = '';
            $optoutFields = [
                'optout_with_unsubscribe_link' => 'Unsubscribe link in the newsletter footer',
                'optout_on_register_account' => 'Collect user preferences when they register an account with the site',
                'optout_change_account_settings' => 'Users can access account settings and update preferences',
                'optout_using_contact' => 'Users can contact us using the contact information provided',
            ];
            foreach ($optoutFields as $key => $label) {
                if (($dataCollection[$key] ?? 'No') === 'Yes') {
                    $items .= '<li>' . $label . '</li>';
                }
            }
            $howWeCollectTpl = @file_get_contents($this->resourceDir . '/how_we_collect.html') ?: '';
            $howWeCollect = '<li>' . str_replace('[ITEMS]', $items, $howWeCollectTpl) . '</li>';
        }

        // ── How We Share section ──
        $howWeShare = '';
        if (($disclosure['third_party_service'] ?? 'No') === 'Yes') {
            $items = '';
            $thirdPartyFields = [
                'ad_service' => 'Ad service',
                'sponsors' => 'Sponsors',
                'marketing_agencies' => 'Marketing agencies',
                'legal_entities' => 'Legal entities',
                'analytics' => 'Analytics',
                'payment_recovery_services' => 'Payment recovery services',
                'data_collection_and_process' => 'Data collection &amp; process',
            ];
            foreach ($thirdPartyFields as $key => $label) {
                if (($disclosure[$key] ?? 'No') === 'Yes') {
                    $items .= '<li>' . $label . '</li>';
                }
            }
            $howWeShareTpl = @file_get_contents($this->resourceDir . '/how_we_share.html') ?: '';
            $howWeShare = '<li>' . str_replace('[ITEMS]', $items, $howWeShareTpl) . '</li>';
        }

        // ── Retention ──
        $retainInformation = $disclosure['time_store_shared_data'] ?? '';
        if ($retainInformation === 'Other') {
            $retainInformation = $disclosure['time_store_shared_data_input'] ?? '';
        }

        // ── Dates ──
        $effectiveDate = '';
        if (!empty($policy['effective_date'])) {
            $effectiveDate = date('d-M-Y', strtotime($policy['effective_date']));
        }
        $updatedAt = date('d-M-Y');
        if (!empty($policy['updated_at'])) {
            $updatedAt = date('d-M-Y', strtotime($policy['updated_at']));
        }

        $replacements = [
            '[POLICY_HEADING]' => $policy['heading'] ?? 'Privacy Policy',
            '[LAST_UPDATED_ON]' => $updatedAt,
            '[EFFECTIVE_DATE]' => $effectiveDate,
            '[COMPANY_NAME]' => htmlspecialchars($websiteInfo['company'] ?? ''),
            '[COMPANY_EMAIL]' => htmlspecialchars($websiteInfo['email'] ?? ''),
            '[COMPANY_ADDRESS]' => htmlspecialchars($websiteInfo['address'] ?? ''),
            '[COMPANY_PHONE]' => htmlspecialchars($websiteInfo['phone'] ?? ''),
            '[COMPANY_ZIPCODE]' => htmlspecialchars($websiteInfo['zipcode'] ?? ''),
            '[COMPANY_STATE]' => htmlspecialchars($websiteInfo['state'] ?? ''),
            '[COMPANY_COUNTRY]' => htmlspecialchars($websiteInfo['country'] ?? ''),
            '[COMPANY_WEBSITE]' => htmlspecialchars($websiteInfo['website'] ?? ''),
            '[COLLECT_INFORMATION]' => $collectInformation,
            '[HOW_WE_COLLECT_INFORMATION]' => $howWeCollect,
            '[HOW_WE_USE_INFORMATION]' => $howWeUse,
            '[HOW_WE_SHARE_INFORMATION]' => $howWeShare,
            '[RETAIN_INFORMATION]' => htmlspecialchars($retainInformation),
            '[POLICY_LINK]' => htmlspecialchars($trackingTech['cookie_policy_link'] ?? '#'),
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    // ── Templates ───────────────────────────────────────────

    /**
     * Get all templates (both types) for a user.
     */
    public function getAllTemplates(int $userId): array
    {
        $cookie = $this->policyRepo->getCookiePolicyTemplates($userId);
        $privacy = $this->policyRepo->getPrivacyPolicyTemplates($userId);

        foreach ($cookie as &$t) {
            $t['type'] = 'cookie';
        }
        foreach ($privacy as &$t) {
            $t['type'] = 'privacy';
        }

        return array_merge($cookie, $privacy);
    }

    /**
     * Load a template formatted as policy data so the wizard can edit it.
     */
    public function getTemplateForEditing(int $templateId, string $type, int $userId): array
    {
        if ($type === 'cookie') {
            $template = $this->policyRepo->getCookiePolicyTemplate($templateId);
            if ($template === null || ((int) ($template['user_id'] ?? 0) !== $userId && empty($template['is_default']))) {
                throw new \RuntimeException('Template not found or access denied');
            }

            return [
                'id' => $template['id'],
                'template_id' => $template['id'],
                'template_name' => $template['template_name'],
                'heading' => $template['heading'] ?? 'Cookie Policy',
                'type_heading' => $template['type_heading'] ?? '',
                'preference_heading' => $template['preference_heading'] ?? '',
                'preference_description' => $template['preference_description'] ?? '',
                'revisit_consent_widget' => $template['revisit_consent_widget'] ?? '',
                'show_audit_table' => $template['show_audit_table'] ?? 0,
                'show_heading' => $template['show_heading'] ?? 1,
                'effective_date' => $template['effective_date'] ?? date('Y-m-d'),
                'policy_content' => $template['policy_content'] ?? '',
            ];
        }

        $template = $this->policyRepo->getPrivacyPolicyTemplate($templateId);
        if ($template === null || ((int) ($template['user_id'] ?? 0) !== $userId && empty($template['is_default']))) {
            throw new \RuntimeException('Template not found or access denied');
        }

        $stepData = json_decode($template['step_data'] ?? '{}', true) ?: $this->getDefaultStepData();

        return [
            'id' => $template['id'],
            'template_id' => $template['id'],
            'template_name' => $template['template_name'],
            'heading' => $template['heading'] ?? 'Privacy Policy',
            'effective_date' => $template['effective_date'] ?? date('Y-m-d'),
            'show_heading' => $template['show_heading'] ?? 1,
            'step_data' => $template['step_data'] ?? '{}',
            'step_data_decoded' => $stepData,
            'policy_content' => $template['policy_content'] ?? '',
        ];
    }

    /**
     * Save a cookie policy wizard step to a template (instead of a site policy).
     */
    public function saveCookieTemplateStep(int $templateId, int $userId, string $step, array $input): array
    {
        $template = $this->policyRepo->getCookiePolicyTemplate($templateId);
        if ($template === null || ((int) ($template['user_id'] ?? 0) !== $userId && empty($template['is_default']))) {
            throw new \RuntimeException('Template not found or access denied');
        }

        if ($step === 'cookie_types') {
            $this->policyRepo->updateCookiePolicyTemplate($templateId, [
                'type_heading' => $input['type_heading'] ?? 'Types of Cookies we use',
                'show_audit_table' => !empty($input['show_audit_table']) ? 1 : 0,
                'show_heading' => !empty($input['show_heading']) ? 1 : 0,
            ]);
        } elseif ($step === 'preference') {
            $this->policyRepo->updateCookiePolicyTemplate($templateId, [
                'preference_heading' => $input['preference_heading'] ?? 'Manage cookie preferences',
                'preference_description' => $input['preference_description'] ?? '',
                'effective_date' => $input['effective_date'] ?? date('Y-m-d'),
            ]);
        }

        if ($step === 'preview' || $step === 'finish') {
            return $this->generateAndSaveCookieTemplate($templateId);
        }

        // Reload and return
        return $this->getTemplateForEditing($templateId, 'cookie', $userId);
    }

    /**
     * Generate cookie policy HTML and save it on the template.
     */
    public function generateAndSaveCookieTemplate(int $templateId): array
    {
        $template = $this->policyRepo->getCookiePolicyTemplate($templateId);
        if ($template === null) {
            return [];
        }

        $html = $this->generateCookiePolicyHtml($template);
        $this->policyRepo->updateCookiePolicyTemplate($templateId, [
            'policy_content' => $html,
            'content' => $html,
        ]);

        $template['policy_content'] = $html;
        return $template;
    }

    /**
     * Save a privacy policy wizard step to a template (instead of a site policy).
     */
    public function savePrivacyTemplateStep(int $templateId, int $userId, string $step, array $input): array
    {
        $template = $this->policyRepo->getPrivacyPolicyTemplate($templateId);
        if ($template === null || ((int) ($template['user_id'] ?? 0) !== $userId && empty($template['is_default']))) {
            throw new \RuntimeException('Template not found or access denied');
        }

        $stepData = json_decode($template['step_data'] ?? '{}', true) ?: $this->getDefaultStepData();

        $validSteps = ['website_info', 'data_collection', 'disclosure', 'tracking_tech', 'data_protection'];
        if (\in_array($step, $validSteps, true)) {
            $normalised = $this->normaliseStepData($step, $input);
            $stepData[$step] = $normalised;

            $saveData = ['step_data' => json_encode($stepData)];

            if ($step === 'data_protection') {
                if (!empty($normalised['effective_date'])) {
                    $saveData['effective_date'] = $normalised['effective_date'];
                }
                $saveData['show_heading'] = !empty($input['show_heading']) ? 1 : 0;
            }

            $this->policyRepo->updatePrivacyPolicyTemplate($templateId, $saveData);
        }

        if ($step === 'preview' || $step === 'finish') {
            return $this->generateAndSavePrivacyTemplate($templateId);
        }

        return $this->getTemplateForEditing($templateId, 'privacy', $userId);
    }

    /**
     * Generate privacy policy HTML and save it on the template.
     */
    public function generateAndSavePrivacyTemplate(int $templateId): array
    {
        $template = $this->policyRepo->getPrivacyPolicyTemplate($templateId);
        if ($template === null) {
            return [];
        }

        $stepData = json_decode($template['step_data'] ?? '{}', true) ?: $this->getDefaultStepData();
        $html = $this->generatePrivacyPolicyHtml($template, $stepData);

        $this->policyRepo->updatePrivacyPolicyTemplate($templateId, [
            'policy_content' => $html,
            'content' => $html,
        ]);

        $template['policy_content'] = $html;
        $template['step_data_decoded'] = $stepData;
        return $template;
    }

    /**
     * Save current site policy as a reusable template.
     */
    public function saveAsTemplate(int $userId, string $type, string $templateName, int $siteId, int $languageId): int
    {
        if ($type === 'cookie') {
            $policy = $this->policyRepo->getCookiePolicy($siteId, $languageId);
            if ($policy === null) {
                throw new \RuntimeException('No cookie policy to save as template');
            }

            return $this->policyRepo->saveCookiePolicyTemplate($userId, [
                'template_name' => $templateName,
                'language_id' => $languageId,
                'heading' => $policy['heading'] ?? '',
                'type_heading' => $policy['type_heading'] ?? '',
                'preference_heading' => $policy['preference_heading'] ?? '',
                'preference_description' => $policy['preference_description'] ?? '',
                'revisit_consent_widget' => $policy['revisit_consent_widget'] ?? '',
                'show_audit_table' => $policy['show_audit_table'] ?? 0,
                'show_heading' => $policy['show_heading'] ?? 1,
                'effective_date' => $policy['effective_date'],
                'content' => $policy['policy_content'] ?? '',
                'policy_content' => $policy['policy_content'] ?? '',
            ]);
        }

        $policy = $this->policyRepo->getPrivacyPolicy($siteId, $languageId);
        if ($policy === null) {
            throw new \RuntimeException('No privacy policy to save as template');
        }

        return $this->policyRepo->savePrivacyPolicyTemplate($userId, [
            'template_name' => $templateName,
            'language_id' => $languageId,
            'heading' => $policy['heading'] ?? '',
            'show_heading' => $policy['show_heading'] ?? 1,
            'url_key' => $policy['url_key'] ?? '',
            'step_data' => $policy['step_data'] ?? '{}',
            'effective_date' => $policy['effective_date'],
            'content' => $policy['policy_content'] ?? '',
            'policy_content' => $policy['policy_content'] ?? '',
        ]);
    }

    /**
     * Apply a template to one or more sites.
     *
     * Copies the structured data and regenerates HTML for each target site,
     * swapping the domain in the privacy policy's website_info.
     */
    public function applyTemplate(int $templateId, string $type, array $siteIds, int $languageId): void
    {
        if ($type === 'cookie') {
            $template = $this->policyRepo->getCookiePolicyTemplate($templateId);
            if ($template === null) {
                throw new \RuntimeException('Cookie policy template not found');
            }

            foreach ($siteIds as $siteId) {
                $siteId = (int) $siteId;
                $this->policyRepo->saveCookiePolicy($siteId, $languageId, [
                    'heading' => $template['heading'] ?? 'Cookie Policy',
                    'type_heading' => $template['type_heading'] ?? '',
                    'preference_heading' => $template['preference_heading'] ?? '',
                    'preference_description' => $template['preference_description'] ?? '',
                    'revisit_consent_widget' => $template['revisit_consent_widget'] ?? '',
                    'show_audit_table' => $template['show_audit_table'] ?? 0,
                    'show_heading' => $template['show_heading'] ?? 1,
                    'effective_date' => date('Y-m-d'),
                    'url_key' => bin2hex(random_bytes(16)),
                    'applied_template_id' => $templateId,
                ]);
                // Regenerate HTML from the structured fields
                $this->generateAndSaveCookiePolicy($siteId, $languageId);
            }
        } else {
            $template = $this->policyRepo->getPrivacyPolicyTemplate($templateId);
            if ($template === null) {
                throw new \RuntimeException('Privacy policy template not found');
            }

            $stepData = json_decode($template['step_data'] ?? '{}', true) ?: $this->getDefaultStepData();

            foreach ($siteIds as $siteId) {
                $siteId = (int) $siteId;

                // Swap the website domain in step_data with the target site's domain
                $siteStepData = $stepData;
                $siteDomain = $this->getSiteDomain($siteId);
                if ($siteDomain !== '') {
                    $siteStepData['website_info']['website'] = $siteDomain;
                }

                $this->policyRepo->savePrivacyPolicy($siteId, $languageId, [
                    'heading' => $template['heading'] ?? 'Privacy Policy',
                    'step_data' => json_encode($siteStepData),
                    'show_heading' => $template['show_heading'] ?? 1,
                    'effective_date' => date('Y-m-d'),
                    'url_key' => bin2hex(random_bytes(16)),
                    'applied_template_id' => $templateId,
                ]);
                // Regenerate HTML from the structured fields with domain swap
                $this->generateAndSavePrivacyPolicy($siteId, $languageId);
            }
        }
    }

    /**
     * Clear a site's policy content (reset to empty so a different source can be used).
     */
    public function clearPolicy(string $type, int $siteId, int $languageId): void
    {
        if ($type === 'cookie') {
            $policy = $this->policyRepo->getCookiePolicy($siteId, $languageId);
            if ($policy !== null) {
                $this->policyRepo->saveCookiePolicy($siteId, $languageId, [
                    'policy_content' => null,
                ]);
            }
        } else {
            $policy = $this->policyRepo->getPrivacyPolicy($siteId, $languageId);
            if ($policy !== null) {
                $this->policyRepo->savePrivacyPolicy($siteId, $languageId, [
                    'policy_content' => null,
                ]);
            }
        }
    }

    /**
     * Get policy status for all given site IDs (which sites have generated content).
     *
     * @return array<int, array{cookie: bool, privacy: bool, cookie_template_id: int, privacy_template_id: int}>
     */
    public function getSitePolicyStatus(array $siteIds, int $languageId): array
    {
        $status = [];
        foreach ($siteIds as $siteId) {
            $siteId = (int) $siteId;
            $cookie = $this->policyRepo->getCookiePolicy($siteId, $languageId);
            $privacy = $this->policyRepo->getPrivacyPolicy($siteId, $languageId);
            $status[$siteId] = [
                'cookie' => !empty($cookie['policy_content']),
                'privacy' => !empty($privacy['policy_content']),
                'cookie_template_id' => (int) ($cookie['applied_template_id'] ?? 0),
                'privacy_template_id' => (int) ($privacy['applied_template_id'] ?? 0),
            ];
        }
        return $status;
    }

    /**
     * Rename a template (verifying ownership).
     */
    public function renameTemplate(int $templateId, string $type, int $userId, string $newName): void
    {
        if ($type === 'cookie') {
            $template = $this->policyRepo->getCookiePolicyTemplate($templateId);
            if ($template === null || ((int) ($template['user_id'] ?? 0) !== $userId && empty($template['is_default']))) {
                throw new \RuntimeException('Template not found or access denied');
            }
            $this->policyRepo->updateCookiePolicyTemplate($templateId, ['template_name' => $newName]);
        } else {
            $template = $this->policyRepo->getPrivacyPolicyTemplate($templateId);
            if ($template === null || ((int) ($template['user_id'] ?? 0) !== $userId && empty($template['is_default']))) {
                throw new \RuntimeException('Template not found or access denied');
            }
            $this->policyRepo->updatePrivacyPolicyTemplate($templateId, ['template_name' => $newName]);
        }
    }

    /**
     * Delete a template (verifying ownership).
     */
    public function deleteTemplate(int $templateId, string $type, int $userId): void
    {
        if ($type === 'cookie') {
            $template = $this->policyRepo->getCookiePolicyTemplate($templateId);
            if ($template !== null && (int) $template['user_id'] === $userId) {
                $this->policyRepo->deleteCookiePolicyTemplate($templateId);
            }
        } else {
            $template = $this->policyRepo->getPrivacyPolicyTemplate($templateId);
            if ($template !== null && (int) $template['user_id'] === $userId) {
                $this->policyRepo->deletePrivacyPolicyTemplate($templateId);
            }
        }
    }

    /**
     * Get the sites each template is applied to (for display as tags).
     *
     * @return array<int, list<array{id: int, domain: string}>> Keyed by template ID
     */
    public function getTemplateSites(array $templates): array
    {
        $result = [];
        foreach ($templates as $t) {
            $id = (int) $t['id'];
            $type = $t['type'] ?? '';
            $table = $type === 'cookie' ? 'oci_cookie_policies' : 'oci_privacy_policies';

            $rows = $this->db->fetchAllAssociative(
                "SELECT s.id, s.domain FROM {$table} p
                 INNER JOIN oci_sites s ON s.id = p.site_id
                 WHERE p.applied_template_id = :tid AND s.deleted_at IS NULL
                 ORDER BY s.domain",
                ['tid' => $id],
            );

            $result[$id] = $rows;
        }

        return $result;
    }

    // ── Helpers ──────────────────────────────────────────────

    /**
     * Get a site's domain URL from the database.
     */
    private function getSiteDomain(int $siteId): string
    {
        $domain = $this->db->fetchOne(
            'SELECT domain FROM oci_sites WHERE id = :id',
            ['id' => $siteId],
        );

        return $domain !== false ? (string) $domain : '';
    }

    // ── Default step data ───────────────────────────────────

    /**
     * Port of legacy policy_fields() from custom_functions.php.
     */
    public function getDefaultStepData(): array
    {
        return [
            'website_info' => [
                'website' => '',
                'company' => '',
                'email' => '',
                'phone' => '',
                'purpose' => '',
                'purpose_input' => '',
                'address' => '',
                'zipcode' => '',
                'state' => '',
                'country' => '',
                'agreement' => 'No',
            ],
            'data_collection' => [
                'create_account_on_website' => 'Yes',
                'newsletters_optout' => 'Yes',
                'optout_with_unsubscribe_link' => 'No',
                'optout_on_register_account' => 'No',
                'optout_change_account_settings' => 'No',
                'optout_using_contact' => 'No',
                'personal_info' => 'No',
                'other_personal_info' => '',
                'name' => 'No',
                'email' => 'No',
                'mobile' => 'No',
                'sm_profile' => 'No',
                'dob' => 'No',
                'address' => 'No',
                'work_address' => 'No',
                'payment_info' => 'No',
                'collected_with_user_consent' => 'No',
                'consent_method_used' => '',
                'under_18' => 'No',
                'parental_consent_proof' => '',
                'under_13' => 'No',
                'ip_device_info_country' => 'No',
                'data_identity' => 'No',
                'data_identity_source' => '',
            ],
            'disclosure' => [
                'marketing_promotional' => 'No',
                'creating_user_account' => 'No',
                'testimonals' => 'No',
                'feedback_collection' => 'No',
                'enforce_tc' => 'No',
                'processing_payment' => 'No',
                'support' => 'No',
                'administration_info' => 'No',
                'targeted_advertising' => 'No',
                'manage_customer_order' => 'No',
                'site_protection' => 'No',
                'user_to_user_comments' => 'No',
                'dispute_resolution' => 'No',
                'manage_user_account' => 'No',
                'accept_payment' => 'No',
                'payment_vendor' => '',
                'users_attend_to_upload' => 'No',
                'third_party_service' => 'No',
                'ad_service' => 'No',
                'sponsors' => 'No',
                'marketing_agencies' => 'No',
                'legal_entities' => 'No',
                'analytics' => 'No',
                'payment_recovery_services' => 'No',
                'data_collection_and_process' => 'No',
                'third_party_monitored' => 'No',
                'time_store_shared_data' => '',
                'time_store_shared_data_input' => '',
                'time_store_data' => '',
                'time_store_data_input' => '',
                'allowed_to_disclose_user_data' => 'No',
                'links_to_third_party' => 'No',
            ],
            'tracking_tech' => [
                'cookie_policy_link' => '',
                'necessary_cookies' => 'Yes',
                'functional_cookies' => 'No',
                'analytics_cookies' => 'No',
                'performance_cookies' => 'No',
                'advertisement_cookies' => 'No',
                'device_app_unique_identities' => 'No',
            ],
            'data_protection' => [
                'contact_name' => '',
                'contact_email' => '',
                'contact_address' => '',
                'eu_office' => 'No',
                'eu_officer_name' => '',
                'eu_officer_email' => '',
                'eu_officer_address' => '',
                'eu_rep' => 'No',
                'eu_rep_name' => '',
                'eu_rep_email' => '',
                'eu_rep_address' => '',
                'effective_date' => '',
            ],
        ];
    }

    /**
     * Normalise incoming form data for a given privacy policy step.
     * Mirrors the legacy policy_fields() checkbox-to-Yes/No conversion.
     */
    private function normaliseStepData(string $step, array $input): array
    {
        $defaults = $this->getDefaultStepData()[$step] ?? [];
        $result = [];

        foreach ($defaults as $key => $defaultValue) {
            if (\in_array($defaultValue, ['Yes', 'No'], true)) {
                // Boolean field — treat as checkbox/toggle
                $result[$key] = !empty($input[$key]) && $input[$key] !== 'No' ? 'Yes' : 'No';
            } else {
                // Text field
                $result[$key] = $input[$key] ?? $defaultValue;
            }
        }

        return $result;
    }
}
