<?php

declare(strict_types=1);

namespace OCI\Cookie\Controller;

use OCI\Cookie\Repository\CookieRegisterRepositoryInterface;
use OCI\Cookie\Service\CookieRegisterDiffService;
use OCI\Dashboard\Service\DashboardService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /cookies/changes — Register change timeline for the current site.
 *
 * Shows every diff the scan-completion snapshot produced: cookies and
 * beacons added, removed, recategorized or with flipped attributes, newest
 * first, with the per-site alert-mail toggle in the header.
 */
final class CookieChangesHandler implements RequestHandlerInterface
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly CookieRegisterRepositoryInterface $registerRepo,
        private readonly CookieRegisterDiffService $diffService,
        private readonly DashboardService $dashboardService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $queryParams = $request->getQueryParams();

        $resolved = $this->dashboardService->resolveSiteId($user, $request->getCookieParams());
        if (isset($resolved['redirect'])) {
            return ApiResponse::redirect($resolved['redirect']);
        }

        $siteId = (int) $resolved['siteId'];

        $page = max(1, (int) ($queryParams['page'] ?? 1));
        $entryType = \in_array($queryParams['type'] ?? '', ['cookie', 'beacon'], true)
            ? $queryParams['type']
            : null;

        $result = $this->registerRepo->getChanges($siteId, $page, self::PER_PAGE, $entryType);

        // Decode JSON payloads and group by calendar day for the timeline
        $byDay = [];
        foreach ($result['items'] as $row) {
            $row['old_value'] = json_decode((string) ($row['old_value'] ?? ''), true);
            $row['new_value'] = json_decode((string) ($row['new_value'] ?? ''), true);
            $day = substr((string) $row['created_at'], 0, 10);
            $byDay[$day][] = $row;
        }

        $templateData = [
            'title' => 'Register changes',
            'active_page' => 'cookies',
            'user' => $user,
            'siteId' => $siteId,
            'sites' => $resolved['sites'],
            'changesByDay' => $byDay,
            'total' => $result['total'],
            'page' => $page,
            'totalPages' => $result['total'] > 0 ? (int) ceil($result['total'] / self::PER_PAGE) : 0,
            'entryType' => $entryType,
            'alertsEnabled' => $this->diffService->areAlertsEnabled($siteId),
        ];

        return ApiResponse::html($this->twig->render('pages/cookies/changes.html.twig', $templateData));
    }
}
