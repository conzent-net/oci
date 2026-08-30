<?php

declare(strict_types=1);

namespace OCI\Consent\Controller;

use Nyholm\Psr7\Response;
use OCI\Consent\Repository\ConsentRepositoryInterface;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /consents/export — Download the consent log as CSV.
 *
 * Honours the same status/date filters as the list page. This is the
 * proof-of-consent path: a supervisory-authority request for the underlying
 * records must be servable without direct database access.
 */
final class ConsentExportHandler implements RequestHandlerInterface
{
    private const BATCH_SIZE = 2000;
    private const COLUMNS = [
        'id', 'consent_session', 'consented_domain', 'consent_status',
        'consent_date', 'user_consent_time', 'ip_pseudonym', 'country',
        'language', 'categories', 'tcf_data', 'gacm_data',
    ];

    public function __construct(
        private readonly ConsentRepositoryInterface $consentRepo,
        private readonly DashboardService $dashboardService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $resolved = $this->dashboardService->resolveSiteId($user, $request->getCookieParams());
        if (isset($resolved['redirect'])) {
            return ApiResponse::redirect($resolved['redirect']);
        }
        $siteId = (int) $resolved['siteId'];

        $queryParams = $request->getQueryParams();
        $filters = [
            'status' => $queryParams['status'] ?? '',
            'date_from' => $queryParams['date_from'] ?? '',
            'date_to' => $queryParams['date_to'] ?? '',
        ];

        // php://temp spills to disk past 2 MB, so a large export never
        // has to fit in memory as one string.
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return ApiResponse::error('Export failed', 500);
        }

        fputcsv($stream, self::COLUMNS);

        $afterId = 0;
        do {
            $rows = $this->consentRepo->getConsentLogForExport($siteId, $filters, $afterId, self::BATCH_SIZE);
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                fputcsv($stream, [
                    $row['id'],
                    $row['consent_session'],
                    $row['consented_domain'],
                    $row['consent_status'],
                    $row['consent_date'],
                    $row['user_consent_time'],
                    $row['ip_address'],
                    $row['country'],
                    $row['language'],
                    $row['categories'],
                    $row['tcf_data'],
                    $row['gacm_data'],
                ]);
            }
        } while (\count($rows) === self::BATCH_SIZE);

        rewind($stream);

        $filename = sprintf('consent-log-site%d-%s.csv', $siteId, date('Y-m-d'));

        return new Response(200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ], $stream);
    }
}
