<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /sites/associated — Manage a site's associated domains.
 *
 * An associated domain is an alias (a second hostname pointing at the same
 * site) that shares this site's banner, cookie list and consent config. The
 * banner's domain guard only runs on registered hostnames, so without an
 * entry here the script refuses to load on the alias.
 */
final class AssociatedDomainListHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed>|null $user */
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $queryParams = $request->getQueryParams();
        $cookies = $request->getCookieParams();

        // Resolve site (query param → cookie → first site)
        $sites = $this->siteRepo->findAllByUser($userId);
        if ($sites === []) {
            return ApiResponse::redirect('/sites');
        }

        $siteIds = array_map(static fn(array $s): int => (int) $s['id'], $sites);
        $siteId = 0;

        if (isset($queryParams['site_id'])) {
            $siteId = (int) $queryParams['site_id'];
        } elseif (isset($cookies['site_id']) && \in_array((int) $cookies['site_id'], $siteIds, true)) {
            $siteId = (int) $cookies['site_id'];
        }

        if (!\in_array($siteId, $siteIds, true)) {
            $siteId = $siteIds[0];
        }

        $currentSite = $this->siteRepo->findById($siteId);
        $associatedDomains = $this->siteRepo->getAssociatedDomains($siteId);

        $html = $this->twig->render('pages/sites/associated.html.twig', [
            'title' => 'Associated Domains',
            'active_page' => 'associated-domains',
            'user' => $user,
            'sites' => $sites,
            'currentSite' => $currentSite,
            'siteId' => $siteId,
            'associatedDomains' => $associatedDomains,
        ]);

        return ApiResponse::html($html);
    }
}
