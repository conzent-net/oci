<?php

declare(strict_types=1);

namespace OCI\Identity\Controller;

use OCI\Admin\Service\AuditLogService;
use OCI\Http\Handler\RequestHandlerInterface;
use OCI\Http\Response\ApiResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment as TwigEnvironment;

/**
 * GET /account/activity — the account's own audit trail.
 *
 * Transparency surface: every state-changing request on the account (who,
 * what, when, from where) plus the granular domain entries, read-only,
 * scoped strictly to the signed-in user. Part of the open core.
 */
final class AccountActivityHandler implements RequestHandlerInterface
{
    private const PER_PAGE = 50;

    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly TwigEnvironment $twig,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $user = $request->getAttribute('user');
        if ($user === null) {
            return ApiResponse::redirect('/login');
        }

        $page = max(1, (int) ($request->getQueryParams()['page'] ?? 1));

        $result = $this->auditLog->list(['user_id' => (int) $user['id']], $page, self::PER_PAGE);
        $totalPages = max(1, (int) ceil($result['total'] / self::PER_PAGE));

        // Precompute a one-line detail per entry (new_values is stored JSON).
        $entries = [];
        foreach ($result['items'] as $entry) {
            $detail = '';
            $values = json_decode((string) ($entry['new_values'] ?? ''), true);
            if (\is_array($values)) {
                $detail = isset($values['path'])
                    ? (string) $values['path']
                    : mb_substr(implode(', ', array_map(
                        static fn ($k, $v): string => $k . ': ' . (\is_scalar($v) ? (string) $v : json_encode($v)),
                        array_keys($values),
                        $values,
                    )), 0, 120);
            }
            $entry['detail'] = $detail;
            $entries[] = $entry;
        }

        return ApiResponse::html($this->twig->render('pages/account/activity.html.twig', [
            'title' => 'Account Activity',
            'active_page' => 'account-activity',
            'user' => $user,
            'entries' => $entries,
            'total' => $result['total'],
            'page' => $page,
            'totalPages' => $totalPages,
        ]));
    }
}
