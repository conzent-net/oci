<?php

declare(strict_types=1);

namespace OCI\Admin\Console;

use OCI\Scanning\Repository\ScanRepositoryInterface;

/**
 * bin/oci scanner:list, scanner:disable, scanner:enable, scanner:remove.
 *
 * scanner:register adds or updates a scan server and scanner:health pings
 * them; these complete the set the Admin module's Scan Servers screen offers,
 * which the open-source edition does not include. Thin wrappers over the scan
 * repository. Scans are only sent to active servers, so a disabled server
 * receives no new scans.
 */
final class ScanServerCommands
{
    public function __construct(
        private readonly ScanRepositoryInterface $scans,
        private readonly OperatorContext $context,
    ) {}

    public function run(OperatorConsole $io): int
    {
        return match ($io->command()) {
            'scanner:list' => $this->list($io),
            'scanner:disable' => $this->setActive($io, false),
            'scanner:enable' => $this->setActive($io, true),
            'scanner:remove' => $this->remove($io),
            default => 1,
        };
    }

    /** @return list<array{0: string, 1: string}> */
    public static function help(): array
    {
        return [
            ['scanner:list', 'List registered scan servers'],
            ['scanner:disable --id=N', 'Stop sending scans to a server'],
            ['scanner:enable --id=N', 'Send scans to a disabled server again'],
            ['scanner:remove --id=N', 'Remove a server registration'],
        ];
    }

    private function list(OperatorConsole $io): int
    {
        $servers = $this->scans->getAllScanServers();
        if ($servers === []) {
            $io->line('No scan servers registered. Register the bundled one with: php bin/oci scanner:register');

            return 0;
        }

        $io->line(str_pad('ID', 5) . str_pad('NAME', 24) . str_pad('URL', 40) . str_pad('STATE', 10) . 'LAST HEARTBEAT');
        foreach ($servers as $s) {
            $io->line(
                str_pad((string) $s['id'], 5)
                . str_pad((string) $s['server_name'], 24)
                . str_pad((string) $s['server_url'], 40)
                . str_pad((int) $s['is_active'] === 1 ? 'active' : 'disabled', 10)
                . (string) ($s['last_heartbeat_at'] ?? 'never'),
            );
        }

        return 0;
    }

    private function setActive(OperatorConsole $io, bool $active): int
    {
        $server = $this->find($io);
        if ($server === null) {
            return 1;
        }
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }
        if ((int) $server['is_active'] === ($active ? 1 : 0)) {
            $io->line("Scan server #{$server['id']} is already " . ($active ? 'active' : 'disabled') . '. Nothing changed.');

            return 0;
        }

        $this->scans->updateScanServer((int) $server['id'], ['is_active' => $active ? 1 : 0]);
        $this->context->record($io, $actor['id'], $active ? 'enable' : 'disable', 'ScanServer', (int) $server['id'], ['is_active' => (int) $server['is_active']], ['is_active' => $active ? 1 : 0]);
        $io->line("Scan server #{$server['id']} ({$server['server_name']}) is " . ($active ? 'active again.' : 'disabled: it receives no new scans.'));

        return 0;
    }

    private function remove(OperatorConsole $io): int
    {
        $server = $this->find($io);
        if ($server === null) {
            return 1;
        }
        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }
        if (!$io->confirm("Remove scan server #{$server['id']} ({$server['server_name']}, {$server['server_url']})?")) {
            $io->line('Nothing changed.');

            return 1;
        }

        $this->scans->deleteScanServer((int) $server['id']);
        $this->context->record($io, $actor['id'], 'delete', 'ScanServer', (int) $server['id'], ['server_name' => $server['server_name'], 'server_url' => $server['server_url']], null);
        $io->line("Removed scan server #{$server['id']}. Register one again with: php bin/oci scanner:register");

        return 0;
    }

    /** @return array<string, mixed>|null */
    private function find(OperatorConsole $io): ?array
    {
        $id = $io->option('id');
        if ($id === null || !ctype_digit($id)) {
            $io->error('Give the server number with --id=N (see scanner:list).');

            return null;
        }
        foreach ($this->scans->getAllScanServers() as $s) {
            if ((int) $s['id'] === (int) $id) {
                return $s;
            }
        }
        $io->error("No scan server #{$id}.");

        return null;
    }
}
