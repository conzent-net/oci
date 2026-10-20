<?php

declare(strict_types=1);

namespace OCI\Report\Service;

use Twig\Environment as TwigEnvironment;

/**
 * Renders report data into self-contained HTML using Twig templates.
 */
final class ReportRenderService
{
    public function __construct(
        private readonly TwigEnvironment $twig,
    ) {}

    /**
     * Render a full report as self-contained HTML.
     *
     * @param array<string, mixed> $reportData
     */
    public function render(array $reportData): string
    {
        return $this->twig->render('emails/report.html.twig', $reportData);
    }
}
