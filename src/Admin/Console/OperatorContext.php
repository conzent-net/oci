<?php

declare(strict_types=1);

namespace OCI\Admin\Console;

use OCI\Admin\Service\AuditLogService;
use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Module\ModuleRegistry;

/**
 * What every operator command shares: who is acting, the audit trail, and
 * the account lookups.
 *
 * A command line has no signed-in user. An action is recorded in the audit
 * log with no user (the Audit log screen shows it as "System"), IP "cli"
 * and the command as user agent, unless --by=EMAIL names the administrator
 * acting, who then appears instead.
 */
final class OperatorContext
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * The administrator named by --by, or null when none was given.
     *
     * @return array{ok: bool, id: ?int, error?: string}
     */
    public function actor(OperatorConsole $io): array
    {
        $email = $io->option('by');
        if ($email === null || $email === '') {
            return ['ok' => true, 'id' => null];
        }

        $user = $this->users->findByEmail($email);
        if ($user === null || ($user['role'] ?? '') !== 'admin' || (int) ($user['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'id' => null, 'error' => "--by={$email} is not an active administrator."];
        }

        return ['ok' => true, 'id' => (int) $user['id']];
    }

    /**
     * @param array<string, mixed>|null $old
     * @param array<string, mixed>|null $new
     */
    public function record(OperatorConsole $io, ?int $actorId, string $action, string $entityType, ?int $entityId, ?array $old = null, ?array $new = null): void
    {
        $this->audit->log(
            userId: $actorId,
            action: $action,
            entityType: $entityType,
            entityId: $entityId,
            oldValues: $old,
            newValues: $new,
            ipAddress: 'cli',
            userAgent: 'bin/oci ' . $io->command(),
        );
    }

    /**
     * The account named by --email or --id. Deleted accounts are found only
     * when $includeDeleted is set (restore needs them).
     *
     * @return array<string, mixed>|null
     */
    public function findUser(OperatorConsole $io, bool $includeDeleted = false): ?array
    {
        $id = $io->option('id');
        if ($id !== null && ctype_digit($id)) {
            $user = $this->users->findById((int) $id);
            if ($user === null || (!$includeDeleted && ($user['deleted_at'] ?? null) !== null)) {
                return null;
            }

            return $user;
        }

        $email = $io->option('email');
        if ($email === null || $email === '') {
            return null;
        }
        if (!$includeDeleted) {
            return $this->users->findByEmail($email);
        }
        foreach ($this->users->findPlain(null, $email, true, 50, 0) as $row) {
            if (strcasecmp((string) $row['email'], $email) === 0) {
                return $this->users->findById((int) $row['id']);
            }
        }

        return null;
    }

    /** Administrators that can sign in: role admin, active, not deleted. */
    public function activeAdminCount(): int
    {
        $count = 0;
        foreach ($this->users->findPlain('admin', null, false, 100000, 0) as $row) {
            if ((int) $row['is_active'] === 1) {
                ++$count;
            }
        }

        return $count;
    }

    public static function isActiveAdmin(array $user): bool
    {
        return ($user['role'] ?? '') === 'admin'
            && (int) ($user['is_active'] ?? 0) === 1
            && ($user['deleted_at'] ?? null) === null;
    }

    /**
     * Stop a command that only makes sense with a module this edition does
     * not include (billing:demo fills the Billing module's screens).
     */
    public static function requireModule(?ModuleRegistry $modules, string $module, OperatorConsole $io): bool
    {
        if ($modules !== null && $modules->get($module) !== null) {
            return true;
        }
        $io->error($io->command() . " works with the {$module} module, which this edition does not include.");

        return false;
    }
}
