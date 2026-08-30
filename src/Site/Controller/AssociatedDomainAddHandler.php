<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Banner\Service\ScriptGenerationService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use OCI\Site\Service\SiteCreationService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/sites/associated/add — Add an associated domain to a site.
 *
 * The domain list is baked into the generated script as [ALLOWED_DOMAINS],
 * so the site's script is regenerated on every mutation — without that the
 * change does nothing until the next unrelated banner save.
 */
final class AssociatedDomainAddHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly SiteCreationService $siteCreationService,
        private readonly ScriptGenerationService $scriptService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::json(['success' => false, 'error' => 'Unauthorized'], 401);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $siteId = (int) ($body['site_id'] ?? 0);
        $rawDomain = (string) ($body['domain'] ?? '');
        $policyUrl = trim((string) ($body['privacy_policy_url'] ?? ''));

        if ($siteId === 0 || trim($rawDomain) === '') {
            return ApiResponse::json(['success' => false, 'error' => 'Missing site_id or domain'], 422);
        }

        $userId = (int) $user['id'];
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $domain = strtolower($this->siteCreationService->normaliseDomain($rawDomain));
        $domain = (string) preg_replace('/^www\./', '', $domain);
        $domain = (string) preg_replace('/:\d+$/', '', $domain);

        if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/', $domain) !== 1) {
            return ApiResponse::json(['success' => false, 'error' => 'Enter a valid domain name'], 422);
        }

        if ($policyUrl !== '' && filter_var($policyUrl, FILTER_VALIDATE_URL) === false) {
            return ApiResponse::json(['success' => false, 'error' => 'Privacy policy URL is not a valid URL'], 422);
        }

        $site = $this->siteRepo->findById($siteId);
        $siteDomain = strtolower((string) preg_replace('/^www\./', '', (string) ($site['domain'] ?? '')));
        if ($domain === $siteDomain) {
            return ApiResponse::json(['success' => false, 'error' => 'That is already this site\'s primary domain'], 422);
        }

        if ($this->siteRepo->associatedDomainExists($siteId, $domain)) {
            return ApiResponse::json(['success' => false, 'error' => 'That domain is already associated with this site'], 422);
        }

        $id = $this->siteRepo->addAssociatedDomain($siteId, $domain, $policyUrl !== '' ? $policyUrl : null);

        $this->auditLogService->log(
            userId: $userId,
            action: 'add',
            entityType: 'AssociatedDomain',
            entityId: $id,
            newValues: ['site_id' => $siteId, 'domain' => $domain],
            ipAddress: $request->getServerParams()['REMOTE_ADDR'] ?? null,
            userAgent: $request->getHeaderLine('User-Agent') ?: null,
        );

        $warning = $this->regenerate($siteId);

        $response = ['success' => true, 'id' => $id, 'domain' => $domain];
        if ($warning !== null) {
            $response['warning'] = $warning;
        }

        return ApiResponse::json($response);
    }

    private function regenerate(int $siteId): ?string
    {
        try {
            return $this->scriptService->generate($siteId)
                ? null
                : 'Domain saved but script regeneration failed. Check server logs.';
        } catch (\Throwable $e) {
            return 'Domain saved but script regeneration failed: ' . $e->getMessage();
        }
    }
}
