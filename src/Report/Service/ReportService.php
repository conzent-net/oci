<?php

declare(strict_types=1);

namespace OCI\Report\Service;

use OCI\Identity\Service\MailerService;
use OCI\Report\Repository\ReportRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Main orchestration service for report generation, sending, and scheduling.
 */
final class ReportService
{
    public function __construct(
        private readonly ReportRepositoryInterface $reportRepo,
        private readonly ReportDataService $dataService,
        private readonly ReportRenderService $renderService,
        private readonly MailerService $mailer,
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Generate a report for a site.
     *
     * @return int The new report ID
     */
    public function generate(int $siteId, int $userId, string $reportType, string $start, string $end): int
    {
        $site = $this->siteRepo->findById($siteId);
        $domain = $site['domain'] ?? 'Unknown site';

        $typeLabel = match ($reportType) {
            'consent' => 'Consent',
            'scan' => 'Cookie Scan',
            default => 'Full Compliance',
        };

        $title = "{$typeLabel} Report — {$domain} ({$start} to {$end})";

        // Gather data based on report type
        $data = [
            'title' => $title,
            'site' => $site,
            'domain' => $domain,
            'report_type' => $reportType,
            'period_start' => $start,
            'period_end' => $end,
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ];

        if (\in_array($reportType, ['consent', 'full'], true)) {
            $data['consent'] = $this->dataService->getConsentSection($siteId, $start, $end);
        }

        if (\in_array($reportType, ['scan', 'full'], true)) {
            $data['scan'] = $this->dataService->getScanSection($siteId, $start, $end);
        }

        if ($reportType === 'full') {
            $data['ab_test'] = $this->dataService->getABTestSection($siteId);
        }

        // Render HTML
        $html = $this->renderService->render($data);

        // Store in database
        $reportId = $this->reportRepo->createReport([
            'site_id' => $siteId,
            'user_id' => $userId,
            'report_type' => $reportType,
            'title' => $title,
            'period_start' => $start,
            'period_end' => $end,
            'report_data' => json_encode($data, JSON_THROW_ON_ERROR),
            'report_html' => $html,
            'status' => 'generated',
        ]);

        $this->logger->info('Report generated', [
            'report_id' => $reportId,
            'site_id' => $siteId,
            'type' => $reportType,
        ]);

        return $reportId;
    }

    /**
     * Send a report via email.
     */
    public function send(int $reportId, ?string $emailTo = null): bool
    {
        $report = $this->reportRepo->findById($reportId);
        if ($report === null) {
            return false;
        }

        // Determine recipient
        $to = $emailTo;
        if ($to === null || $to === '') {
            // Fall back to user email via the schedule or report owner
            $site = $this->siteRepo->findById((int) $report['site_id']);
            if ($site === null) {
                return false;
            }
            // Use the schedule email if available
            $schedule = $this->reportRepo->getSchedule((int) $report['site_id'], $report['report_type']);
            $to = $schedule['email_to'] ?? null;
        }

        if ($to === null || $to === '') {
            $this->logger->warning('No email recipient for report', ['report_id' => $reportId]);

            return false;
        }

        $subject = $report['title'] ?? 'Conzent Compliance Report';
        $success = $this->mailer->send($to, $subject, $report['report_html'] ?? '');

        $this->reportRepo->updateReport($reportId, [
            'status' => $success ? 'sent' : 'failed',
            'last_sent_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $success;
    }

    /**
     * Process all due scheduled reports.
     *
     * @return int Number of reports processed
     */
    public function processDueSchedules(): int
    {
        $schedules = $this->reportRepo->getDueSchedules();
        $processed = 0;

        foreach ($schedules as $schedule) {
            try {
                $siteId = (int) $schedule['site_id'];
                $userId = (int) $schedule['user_id'];
                $reportType = $schedule['report_type'];

                // Calculate period: previous calendar month
                $now = new \DateTimeImmutable();
                $start = $now->modify('first day of last month')->format('Y-m-d');
                $end = $now->modify('last day of last month')->format('Y-m-d');

                $reportId = $this->generate($siteId, $userId, $reportType, $start, $end);

                // Send the report
                $emailTo = $schedule['email_to'] ?? $schedule['user_email'] ?? null;
                if ($emailTo !== null && $emailTo !== '') {
                    $this->send($reportId, $emailTo);
                }

                // Advance next_run_at to 1st of next month at 06:00
                $nextRun = $now->modify('first day of next month')->setTime(6, 0)->format('Y-m-d H:i:s');
                $this->reportRepo->updateScheduleAfterRun((int) $schedule['id'], $nextRun);

                $processed++;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process scheduled report', [
                    'schedule_id' => $schedule['id'] ?? 0,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Create or update a report schedule.
     */
    public function updateSchedule(
        int $siteId,
        int $userId,
        string $reportType,
        string $frequency,
        bool $isActive,
        ?string $emailTo,
    ): void {
        // Calculate next_run_at: 1st of next month at 06:00
        $nextRun = (new \DateTimeImmutable())
            ->modify('first day of next month')
            ->setTime(6, 0)
            ->format('Y-m-d H:i:s');

        $this->reportRepo->upsertSchedule([
            'site_id' => $siteId,
            'user_id' => $userId,
            'report_type' => $reportType,
            'frequency' => $frequency,
            'is_active' => $isActive ? 1 : 0,
            'next_run_at' => $nextRun,
            'email_to' => $emailTo,
        ]);
    }

    /**
     * Delete a report.
     */
    public function delete(int $reportId): void
    {
        $this->reportRepo->deleteReport($reportId);
    }

    /**
     * Get a single report.
     *
     * @return array<string, mixed>|null
     */
    public function getReport(int $reportId): ?array
    {
        return $this->reportRepo->findById($reportId);
    }

    /**
     * Get paginated reports for a site.
     *
     * @return array{reports: array, total: int}
     */
    public function getReportsBySite(int $siteId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;

        return [
            'reports' => $this->reportRepo->findBySite($siteId, $perPage, $offset),
            'total' => $this->reportRepo->countBySite($siteId),
        ];
    }

    /**
     * Get the schedule for a site and report type.
     *
     * @return array<string, mixed>|null
     */
    public function getSchedule(int $siteId, string $reportType = 'full'): ?array
    {
        return $this->reportRepo->getSchedule($siteId, $reportType);
    }
}
