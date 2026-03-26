<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /admin/audit-log — View system audit trail (admin only).
 */
final class AuditLogHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly TwigEnvironment $twig,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $params = $request->getQueryParams();
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = 50;

        $filters = [];
        if (!empty($params['entity_type'])) {
            $filters['entity_type'] = $params['entity_type'];
        }
        if (!empty($params['action'])) {
            $filters['action'] = $params['action'];
        }
        if (!empty($params['date_from'])) {
            $filters['date_from'] = $params['date_from'];
        }
        if (!empty($params['date_to'])) {
            $filters['date_to'] = $params['date_to'];
        }
        if (!empty($params['search'])) {
            $filters['search'] = $params['search'];
        }

        $result = $this->auditLogService->list($filters, $page, $perPage);

        $templateData = [
            'title' => 'Audit Log',
            'active_page' => 'admin_audit_log',
            'logs' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'filters' => $filters,
            'entityTypes' => $this->auditLogService->getEntityTypes(),
            'actions' => $this->auditLogService->getActions(),
        ];

        // htmx partial for table pagination/filtering
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return ApiResponse::html($this->twig->render('pages/admin/_audit_log_table.html.twig', $templateData));
        }

        return ApiResponse::html($this->twig->render('pages/admin/audit_log.html.twig', $templateData));
    }
}
