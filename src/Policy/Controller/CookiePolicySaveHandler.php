<?php

declare(strict_types=1);

namespace OCI\Policy\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Policy\Service\PolicyService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/policies/cookie/save — Save cookie policy wizard step.
 */
final class CookiePolicySaveHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly PolicyService $policyService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::error('Unauthorized', 401);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];
        $step = $body['step'] ?? '';
        $templateId = (int) ($body['template_id'] ?? 0);

        // Template editing mode — save to template instead of site policy
        if ($templateId > 0) {
            if ($step === '') {
                return ApiResponse::error('Missing step', 400);
            }

            $result = $this->policyService->saveCookieTemplateStep($templateId, (int) $user['id'], $step, $body);

            $this->auditLogService->log(
                userId: (int) $user['id'],
                action: 'update',
                entityType: 'CookiePolicyTemplate',
                entityId: $templateId,
                newValues: ['step' => $step],
                ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );

            $data = ['policy' => $result];
            if (\in_array($step, ['preview', 'finish'], true)) {
                $data['content'] = $result['policy_content'] ?? '';
                $data['rawcontent'] = htmlentities($result['policy_content'] ?? '');
            }

            return ApiResponse::success($data);
        }

        $siteId = (int) ($body['site_id'] ?? 0);
        $languageId = (int) ($body['language_id'] ?? 1);

        if ($siteId <= 0 || $step === '') {
            return ApiResponse::error('Missing site_id or step', 400);
        }

        $result = $this->policyService->saveCookiePolicyStep($siteId, $languageId, $step, $body);

        $this->auditLogService->log(
            userId: (int) $user['id'],
            action: 'update',
            entityType: 'CookiePolicy',
            newValues: ['site_id' => $siteId, 'language_id' => $languageId, 'step' => $step],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        $response = ['success' => true, 'data' => ['policy' => $result]];

        if (\in_array($step, ['preview', 'finish'], true)) {
            $response['data']['content'] = $result['policy_content'] ?? '';
            $response['data']['rawcontent'] = htmlentities($result['policy_content'] ?? '');
        }

        return ApiResponse::success($response['data']);
    }
}
