<?php

declare(strict_types=1);

namespace OCI\Admin\Controller;

use OCI\Admin\Service\BackupDestinationConfig;
use OCI\Admin\Service\BackupOverviewService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use OCI\Identity\Service\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /admin/backup — Backup & Restore (admin only).
 */
final class BackupIndexHandler implements RequestHandlerInterface
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly BackupOverviewService $overview,
        private readonly BackupDestinationConfig $destinationConfig,
        private readonly CsrfService $csrf,
        private readonly TwigEnvironment $twig,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $query = $request->getQueryParams();
        $page = max(1, (int) ($query['page'] ?? 1));

        // Explicit, never automatic. Re-listing every destination is a real
        // network round-trip, so it happens when somebody asks for it rather
        // than on every page load.
        if (($query['refresh_storage'] ?? '') === '1') {
            $this->overview->refreshStorage();
        }

        $data = [
            'title' => 'Backup and restore',
            'active_page' => 'admin_backup',
            'user' => $user,
            'overview' => $this->overview->overview(),
            'backups' => $this->overview->history(self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $this->overview->total(),
            'page' => $page,
            'perPage' => self::PER_PAGE,
            'csrf_token' => $this->csrf->generate('admin_backup'),
            // Never the credential values — only whether each is set, and
            // whether this host still holds the key to read it.
            'sftp' => $this->destinationConfig->forDisplay('sftp'),
            's3' => $this->destinationConfig->forDisplay('s3'),
        ];

        if ($request->getHeaderLine('HX-Request') === 'true') {
            return ApiResponse::html($this->twig->render('pages/admin/_backup_table.html.twig', $data));
        }

        return ApiResponse::html($this->twig->render('pages/admin/backup.html.twig', $data));
    }
}
