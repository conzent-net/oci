<?php

declare(strict_types=1);

/**
 * The four built-in compliance templates.
 *
 * Lifted out of ApplyTemplateHandler, where they were inline literals. Two
 * reasons that mattered: the agency template feature has to be able to seed
 * itself from the same definitions rather than a second copy that drifts, and
 * a template is data, not control flow.
 *
 * A template is a *partial* settings map. It is merged over whatever the site
 * already has, so anything absent here is deliberately left alone — notably
 * `disable_branding`, custom logos, colours and per-language text, none of
 * which a compliance template has any business overwriting.
 *
 * `iab_support` is what separates the `_tcf` variants, and `tag_fire_enabled`
 * is what separates `advanced` from `basic`. "IAB Advanced" in the agency
 * pitch is `advanced_tcf` — which is worth being honest about internally: it
 * is two booleans and a button set, not a TCF certification.
 */

return [
    'basic' => [
        'label' => 'Basic',
        'description' => 'Accept, reject and customize buttons, Google Consent Mode on, no IAB TCF.',
        'general' => [
            'gcm_enabled' => true,
            'google_consent' => true,
            'iab_support' => false,
        ],
        'site' => [
            'gcm_enabled' => 1,
            'tag_fire_enabled' => 0,
            'block_iframe' => 0,
            'banner_delay_ms' => 100,
        ],
    ],
    'advanced' => [
        'label' => 'Advanced',
        'description' => 'Basic plus tag firing, for sites running Google Tag Manager.',
        'general' => [
            'gcm_enabled' => true,
            'google_consent' => true,
            'iab_support' => false,
        ],
        'site' => [
            'gcm_enabled' => 1,
            'tag_fire_enabled' => 1,
            'block_iframe' => 0,
            'banner_delay_ms' => 100,
        ],
    ],
    'basic_tcf' => [
        'label' => 'Basic + IAB TCF',
        'description' => 'Basic plus the IAB Transparency and Consent Framework.',
        'general' => [
            'gcm_enabled' => true,
            'google_consent' => true,
            'iab_support' => true,
        ],
        'site' => [
            'gcm_enabled' => 1,
            'tag_fire_enabled' => 0,
            'block_iframe' => 0,
            'banner_delay_ms' => 100,
        ],
    ],
    'advanced_tcf' => [
        'label' => 'Advanced + IAB TCF',
        'description' => 'Tag firing and IAB TCF together. Marketed to agencies as "IAB Advanced".',
        'general' => [
            'gcm_enabled' => true,
            'google_consent' => true,
            'iab_support' => true,
        ],
        'site' => [
            'gcm_enabled' => 1,
            'tag_fire_enabled' => 1,
            'block_iframe' => 0,
            'banner_delay_ms' => 100,
        ],
    ],
];
