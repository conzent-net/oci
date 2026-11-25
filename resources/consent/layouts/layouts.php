<?php

/**
 * Banner Layout Registry
 *
 * Each layout is a Twig template that extends the base layout.
 * Users select a layout in /banners → Layout Settings.
 * The ScriptGenerationService renders the selected layout with banner variables.
 *
 * To add a new layout:
 *   1. Create a Twig file in the appropriate law directory (gdpr/, ccpa/)
 *   2. Extend the base template and override blocks
 *   3. Register it here with a unique slug
 */

return [
    // ── GDPR layouts ────────────────────────────────────────
    'gdpr' => [
        'classic' => [
            'name' => 'Classic',
            'description' => 'Bottom bar or corner box with horizontal buttons. The standard consent banner.',
            'template' => 'gdpr/classic.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-classic.svg',
            'positions' => ['box-left', 'box-right', 'banner-bottom', 'banner-top', 'popup-center'],
            'default' => true,
        ],
        'minimal' => [
            'name' => 'Minimal',
            'description' => 'Clean and compact. No title, just message and buttons. Unobtrusive.',
            'template' => 'gdpr/minimal.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-minimal.svg',
            'positions' => ['box-left', 'box-right', 'banner-bottom', 'banner-top'],
        ],
        'stacked' => [
            'name' => 'Stacked',
            'description' => 'Full-width bar with vertically stacked buttons. Clear visual hierarchy.',
            'template' => 'gdpr/stacked.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-stacked.svg',
            'positions' => ['banner-bottom', 'banner-top'],
        ],
        'card' => [
            'name' => 'Card',
            'description' => 'Centered overlay card with shadow. Premium, modal-like appearance.',
            'template' => 'gdpr/card.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-card.svg',
            'positions' => ['popup-center'],
        ],
        'sidebar' => [
            'name' => 'Sidebar',
            'description' => 'Full-height panel that slides in from the side. Modern CMP style.',
            'template' => 'gdpr/sidebar.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-sidebar.svg',
            'positions' => ['box-left', 'box-right'],
        ],
        'hero' => [
            'name' => 'Hero',
            'description' => 'Large image header with gradient. Brand-forward, eye-catching design.',
            'template' => 'gdpr/hero.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-hero.svg',
            'positions' => ['box-left', 'box-right', 'banner-bottom', 'banner-top', 'popup-center'],
        ],
        'dialog' => [
            'name' => 'Dialog',
            'description' => 'Modern centered dialog with inline category toggles and equal-width buttons.',
            'template' => 'gdpr/dialog.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-dialog.svg',
            'positions' => ['popup-center', 'box-left', 'box-right'],
        ],
        'tabcard' => [
            'name' => 'Tabcard',
            'description' => 'Compact card with colored header strip and category toggle grid.',
            'template' => 'gdpr/tabcard.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-tabcard.svg',
            'positions' => ['popup-center', 'box-left', 'box-right'],
        ],
        'wall' => [
            'name' => 'Wall',
            'description' => 'Full consent wall with expandable cookie details in one view.',
            'template' => 'gdpr/wall.html.twig',
            'thumbnail' => 'layouts/thumbnails/gdpr-wall.svg',
            'positions' => ['popup-center'],
        ],
    ],

    // ── CCPA layouts ────────────────────────────────────────
    'ccpa' => [
        'classic' => [
            'name' => 'Classic',
            'description' => 'Standard CCPA opt-out notice with Do Not Sell link.',
            'template' => 'ccpa/classic.html.twig',
            'thumbnail' => 'layouts/thumbnails/ccpa-classic.svg',
            'positions' => ['box-left', 'box-right', 'banner-bottom', 'banner-top'],
            'default' => true,
        ],
    ],
];
