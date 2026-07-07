<?php

declare(strict_types=1);

namespace OCI\Dashboard\Controller;

use OCI\Agency\Repository\AgencyRepositoryInterface;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/dashboard/customer-report — AJAX customer chart data (agency).
 *
 * Returns: JSON [{date, customers}] monthly for 12 months
 */
final class CustomerReportHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AgencyRepositoryInterface $agencyRepo,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $userId = (int) ($user['id'] ?? 0);
        $rows = $this->agencyRepo->getMonthlyCustomers($userId);

        // Gap-fill last 12 months
        $items = [];
        $start = date('Y-m-d', (int) strtotime('today - 12 month'));
        for ($i = 0; $i < 12; $i++) {
            $date = date('m-Y', (int) strtotime($start . " + {$i} month"));
            $items[$date] = ['date' => $date, 'customers' => 0];
        }

        foreach ($rows as $row) {
            $month = (string) $row['date_month'];
            $items[$month] = ['date' => $month, 'customers' => $row['total_customers']];
        }

        return ApiResponse::success(['customers' => array_values($items)]);
    }
}
