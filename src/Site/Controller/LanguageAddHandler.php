<?php

declare(strict_types=1);

namespace OCI\Site\Controller;

use OCI\Banner\Repository\BannerRepositoryInterface;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Monetization\Service\PricingService;
use OCI\Shared\Repository\PlanRepositoryInterface;
use OCI\Site\Repository\LanguageRepositoryInterface;
use OCI\Site\Repository\SiteRepositoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /app/languages/add — Add a language to a site.
 */
final class LanguageAddHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly SiteRepositoryInterface $siteRepo,
        private readonly LanguageRepositoryInterface $languageRepo,
        private readonly BannerRepositoryInterface $bannerRepo,
        private readonly PlanRepositoryInterface $planRepo,
        private readonly PricingService $pricingService,
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
        $languageId = (int) ($body['language_id'] ?? 0);

        if ($siteId === 0 || $languageId === 0) {
            return ApiResponse::json(['success' => false, 'error' => 'Missing site_id or language_id'], 422);
        }

        $userId = (int) $user['id'];
        if (!$this->siteRepo->belongsToUser($siteId, $userId)) {
            return ApiResponse::json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $language = $this->languageRepo->findLanguageById($languageId);
        if ($language === null) {
            return ApiResponse::json(['success' => false, 'error' => 'Language not found'], 404);
        }

        // Enforce plan language limit
        $currentCount = $this->languageRepo->countSiteLanguages($siteId);
        $maxLangs = $this->resolveLimit($userId, 'max_languages');

        if ($maxLangs > 0 && $currentCount >= $maxLangs) {
            return ApiResponse::json([
                'success' => false,
                'error' => "Maximum {$maxLangs} languages allowed in your current plan. Please upgrade to add more.",
            ], 422);
        }

        $isDefault = $currentCount === 0;
        $this->languageRepo->addSiteLanguage($siteId, $languageId, $isDefault);

        // Copy default banner translations for the new language
        $banners = $this->bannerRepo->getSiteBannerSettings($siteId);
        foreach ($banners as $banner) {
            $templateId = (int) ($banner['banner_template_id'] ?? 0);
            if ($templateId > 0) {
                $this->bannerRepo->copyDefaultBannerTranslations((int) $banner['id'], $templateId, $languageId);
            }
        }

        return ApiResponse::json(['success' => true]);
    }

    /**
     * Resolve a numeric plan limit for a user.
     * Returns 0 for unlimited (enterprise or self-hosted).
     */
    private function resolveLimit(int $userId, string $limitKey): int
    {
        if ($this->planRepo->isEnterprise($userId)) {
            return 0;
        }

        $userPlan = $this->planRepo->getUserPlan($userId);
        if ($userPlan === null) {
            return 0;
        }

        // New pricing system: plan_key is available
        $planKey = $userPlan['plan_key'] ?? null;
        if ($planKey !== null) {
            return $this->pricingService->getLimit($planKey, $limitKey);
        }

        return 0;
    }
}
