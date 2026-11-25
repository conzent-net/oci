<?php

declare(strict_types=1);

namespace OCI\Banner\Controller;

use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /banners/content — Redirect to /banners (content editing is now inline).
 */
final class BannerContentHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $siteId = (int) ($queryParams['site_id'] ?? 0);

        $url = '/banners';
        if ($siteId > 0) {
            $url .= '?site_id=' . $siteId;
        }

        return ApiResponse::redirect($url);
    }
}
