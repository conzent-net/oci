<?php

declare(strict_types=1);

namespace OCI\Admin\Console;

use OCI\Identity\Repository\UserRepositoryInterface;
use OCI\Identity\Service\UserService;

/**
 * bin/oci user:* — the accounts and administrators of an install.
 *
 * The open-source edition has no Users screen (it comes with the Admin
 * module), so these are the way to list, add, promote, demote, deactivate,
 * delete, restore and unlock accounts there. Each is a thin wrapper over
 * UserService and the user repository; nothing here reads billing data, so
 * it runs on a database built from the open-source migrations alone.
 *
 * Every change is written to the audit log (see OperatorContext). The install
 * is never left without an active administrator: demoting, deactivating or
 * deleting the last one is refused.
 */
final class UserCommands
{
    /** AuthService refuses a sign-in from this many failed attempts on. */
    private const LOCKOUT_ATTEMPTS = 10;

    private const ROLES = ['admin', 'customer', 'agency'];

    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly UserService $userService,
        private readonly OperatorContext $context,
    ) {}

    public function run(OperatorConsole $io): int
    {
        return match ($io->command()) {
            'user:list' => $this->list($io),
            'user:show' => $this->show($io),
            'user:create' => $this->create($io),
            'user:role' => $this->role($io),
            'user:deactivate' => $this->setActive($io, false),
            'user:activate' => $this->setActive($io, true),
            'user:delete' => $this->delete($io),
            'user:restore' => $this->restore($io),
            'user:unlock' => $this->unlock($io),
            default => $this->usage($io),
        };
    }

    /** @return list<array{0: string, 1: string}> command and one-line description, for bin/oci help */
    public static function help(): array
    {
        return [
            ['user:list [--role=] [--search=] [--deleted]', 'List accounts'],
            ['user:show --email=ADDR', 'Show one account'],
            ['user:create --email=ADDR [--role=admin]', 'Create an account; prints a generated password once'],
            ['user:role --email=ADDR --role=ROLE', 'Make an account admin, customer or agency'],
            ['user:deactivate --email=ADDR', 'Stop an account signing in, and sign it out'],
            ['user:activate --email=ADDR', 'Let a deactivated account sign in again'],
            ['user:delete --email=ADDR', 'Delete an account (it can be restored)'],
            ['user:restore --email=ADDR', 'Restore a deleted account'],
            ['user:unlock --email=ADDR', 'Clear the failed sign-in count of a locked account'],
        ];
    }

    private function usage(OperatorConsole $io): int
    {
        $io->line('Commands for accounts and administrators:');
        foreach (self::help() as [$command, $description]) {
            $io->line('  ' . str_pad($command, 46) . $description);
        }
        $io->line('Every command takes --id=N in place of --email, and --by=ADDR to record the');
        $io->line('administrator acting in the audit log.');

        return 1;
    }

    private function list(OperatorConsole $io): int
    {
        $role = $io->option('role');
        if ($role !== null && !\in_array($role, self::ROLES, true)) {
            $io->error("Unknown role: {$role}. Use admin, customer or agency.");

            return 1;
        }

        $rows = $this->users->findPlain($role, $io->option('search'), $io->flag('deleted'), 1000, 0);
        if ($rows === []) {
            $io->line('No accounts match.');

            return 0;
        }

        $io->line(str_pad('ID', 6) . str_pad('EMAIL', 40) . str_pad('ROLE', 10) . str_pad('STATUS', 10) . 'LAST SIGN-IN');
        foreach ($rows as $row) {
            $io->line(
                str_pad((string) $row['id'], 6)
                . str_pad((string) $row['email'], 40)
                . str_pad((string) $row['role'], 10)
                . str_pad($this->status($row), 10)
                . (string) ($row['last_login_at'] ?? 'never'),
            );
        }
        $io->line(\count($rows) . ' account(s).');

        return 0;
    }

    private function show(OperatorConsole $io): int
    {
        $user = $this->context->findUser($io, true);
        if ($user === null) {
            return $this->notFound($io);
        }

        $name = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
        $io->line('ID:            ' . $user['id']);
        $io->line('Email:         ' . $user['email']);
        $io->line('Name:          ' . ($name !== '' ? $name : '-'));
        $io->line('Role:          ' . $user['role']);
        $io->line('Status:        ' . $this->status($user));
        $io->line('Failed logins: ' . (int) ($user['login_attempts'] ?? 0));
        $io->line('Last sign-in:  ' . ($user['last_login_at'] ?? 'never'));
        $io->line('Created:       ' . ($user['created_at'] ?? '-'));

        return 0;
    }

    private function create(OperatorConsole $io): int
    {
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }

        $email = (string) $io->option('email');
        $role = $io->option('role') ?? 'customer';
        if (!\in_array($role, self::ROLES, true)) {
            $io->error("Unknown role: {$role}. Use admin, customer or agency.");

            return 1;
        }

        // Generated and shown once, as user:password does: a password typed
        // on the command line stays in the shell history.
        $password = substr(bin2hex(random_bytes(12)), 0, 20);

        $result = $this->userService->createUser([
            'email' => $email,
            'username' => $email,
            'first_name' => (string) ($io->option('first-name') ?? ''),
            'last_name' => (string) ($io->option('last-name') ?? ''),
            'password' => $password,
            'role' => $role,
            'is_active' => 1,
        ]);
        if (!$result['success']) {
            foreach ($result['errors'] ?? [] as $message) {
                $io->error((string) $message);
            }

            return 1;
        }

        $this->context->record($io, $actor['id'], 'create', 'User', (int) $result['user_id'], null, ['email' => $email, 'role' => $role]);

        $io->line("Created {$role} account {$email} (ID {$result['user_id']}).");
        $io->line("  Password: {$password}");
        $io->line('  Store it in a password manager: it is not saved anywhere and is not shown again.');
        $io->line('  Change it later with: php bin/oci user:password --email=' . $email);

        return 0;
    }

    private function role(OperatorConsole $io): int
    {
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }

        $user = $this->context->findUser($io);
        if ($user === null) {
            return $this->notFound($io);
        }

        $role = (string) $io->option('role');
        if (!\in_array($role, self::ROLES, true)) {
            $io->error('Give the new role with --role=admin, --role=customer or --role=agency.');

            return 1;
        }
        if ($role === $user['role']) {
            $io->line("{$user['email']} is already {$role}. Nothing changed.");

            return 0;
        }

        $demotingAdmin = $user['role'] === 'admin';
        if ($demotingAdmin && OperatorContext::isActiveAdmin($user) && $this->context->activeAdminCount() <= 1) {
            $io->error("{$user['email']} is the only active administrator. Make another account admin first: the install must keep one.");

            return 1;
        }
        if ($demotingAdmin && !$io->confirm("Remove administrator rights from {$user['email']}?")) {
            $io->line('Nothing changed.');

            return 1;
        }

        $this->userService->changeRole((int) $user['id'], $role);
        $this->context->record($io, $actor['id'], 'update', 'User', (int) $user['id'], ['role' => $user['role']], ['role' => $role]);
        $io->line("{$user['email']} is now {$role}.");

        return 0;
    }

    private function setActive(OperatorConsole $io, bool $active): int
    {
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }

        $user = $this->context->findUser($io);
        if ($user === null) {
            return $this->notFound($io);
        }
        if ((int) $user['is_active'] === ($active ? 1 : 0)) {
            $io->line("{$user['email']} is already " . ($active ? 'active' : 'inactive') . '. Nothing changed.');

            return 0;
        }
        if (!$active && OperatorContext::isActiveAdmin($user) && $this->context->activeAdminCount() <= 1) {
            $io->error("{$user['email']} is the only active administrator and cannot be deactivated: the install must keep one.");

            return 1;
        }

        $this->userService->toggleActive((int) $user['id'], $active);
        $this->context->record($io, $actor['id'], $active ? 'activate' : 'deactivate', 'User', (int) $user['id'], ['is_active' => (int) $user['is_active']], ['is_active' => $active ? 1 : 0]);
        $io->line($active
            ? "{$user['email']} can sign in again."
            : "{$user['email']} is deactivated and was signed out everywhere.");

        return 0;
    }

    private function delete(OperatorConsole $io): int
    {
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }

        $user = $this->context->findUser($io);
        if ($user === null) {
            return $this->notFound($io);
        }
        if (OperatorContext::isActiveAdmin($user) && $this->context->activeAdminCount() <= 1) {
            $io->error("{$user['email']} is the only active administrator and cannot be deleted: the install must keep one.");

            return 1;
        }
        if (!$io->confirm("Delete the account {$user['email']}? It is signed out and can no longer sign in; user:restore brings it back.")) {
            $io->line('Nothing changed.');

            return 1;
        }

        $this->userService->deleteUser((int) $user['id']);
        $this->context->record($io, $actor['id'], 'delete', 'User', (int) $user['id'], ['email' => $user['email'], 'role' => $user['role']], null);
        $io->line("Deleted {$user['email']}. Restore it with: php bin/oci user:restore --id={$user['id']}");

        return 0;
    }

    private function restore(OperatorConsole $io): int
    {
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }

        $user = $this->context->findUser($io, true);
        if ($user === null) {
            return $this->notFound($io);
        }
        if (($user['deleted_at'] ?? null) === null) {
            $io->line("{$user['email']} is not deleted. Nothing changed.");

            return 0;
        }

        $this->userService->restoreUser((int) $user['id']);
        $this->context->record($io, $actor['id'], 'restore', 'User', (int) $user['id'], ['deleted_at' => $user['deleted_at']], ['deleted_at' => null]);
        $io->line("Restored {$user['email']}. It can sign in again.");

        return 0;
    }

    private function unlock(OperatorConsole $io): int
    {
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }

        $user = $this->context->findUser($io);
        if ($user === null) {
            return $this->notFound($io);
        }

        $attempts = (int) ($user['login_attempts'] ?? 0);
        $this->userService->resetLoginAttempts((int) $user['id']);
        $this->context->record($io, $actor['id'], 'unlock', 'User', (int) $user['id'], ['login_attempts' => $attempts], ['login_attempts' => 0]);
        $io->line("Cleared {$attempts} failed sign-in(s) for {$user['email']}.");

        return 0;
    }

    private function notFound(OperatorConsole $io): int
    {
        $who = $io->option('email') ?? $io->option('id');
        $io->error($who === null || $who === ''
            ? 'Name the account with --email=ADDR or --id=N.'
            : "No account found for {$who}.");

        return 1;
    }

    /** @param array<string, mixed> $user */
    private function status(array $user): string
    {
        if (($user['deleted_at'] ?? null) !== null) {
            return 'deleted';
        }
        if ((int) ($user['is_active'] ?? 0) !== 1) {
            return 'inactive';
        }
        if ((int) ($user['login_attempts'] ?? 0) >= self::LOCKOUT_ATTEMPTS) {
            return 'locked';
        }

        return 'active';
    }
}
