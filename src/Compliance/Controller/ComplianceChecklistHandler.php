<?php

declare(strict_types=1);

namespace OCI\Compliance\Controller;

use OCI\Compliance\Service\ChecklistService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /compliance — Interactive compliance checklist page.
 */
final class ComplianceChecklistHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly ChecklistService $checklistService,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $userId = (int) $user['id'];
        $queryParams = $request->getQueryParams();
        $regulationId = $queryParams['regulation'] ?? 'gdpr';

        $overview = $this->checklistService->getOverview($userId);
        $checklist = $this->checklistService->getRegulationChecklist($userId, $regulationId);

        // If regulation not found, fall back to first available
        if ($checklist === null && !empty($overview)) {
            $regulationId = $overview[0]['regulations'][0]['id'] ?? 'gdpr';
            $checklist = $this->checklistService->getRegulationChecklist($userId, $regulationId);
        }

        return ApiResponse::html($this->twig->render('pages/compliance/checklist.html.twig', [
            'title' => 'Compliance Checklist',
            'user' => $user,
            'overview' => $overview,
            'checklist' => $checklist,
            'currentRegulation' => $regulationId,
        ]));
    }
}
