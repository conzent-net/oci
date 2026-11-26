<?php

declare(strict_types=1);

namespace OCI\Admin\Console;

use OCI\Cookie\Service\CookieReclassificationService;
use OCI\Scanning\Service\BeaconReclassificationService;

/**
 * bin/oci requests:* — the cookie and beacon reclassification requests site
 * owners submit ("this is in the wrong category").
 *
 * Core accepts them on every edition, and the cookie list shows "Change
 * requested" until someone decides; the screens that decide come with the
 * Admin module, which the open-source edition does not include, so these
 * commands are its way to act on them. Thin wrappers over the two
 * reclassification services, which also write the audit entries.
 *
 * Approving or rejecting needs --by=EMAIL: the services record the deciding
 * administrator on the request and in the audit log.
 */
final class RequestCommands
{
    private const TYPES = ['cookie', 'beacon'];

    public function __construct(
        private readonly CookieReclassificationService $cookies,
        private readonly BeaconReclassificationService $beacons,
        private readonly OperatorContext $context,
    ) {}

    public function run(OperatorConsole $io): int
    {
        return match ($io->command()) {
            'requests:list' => $this->list($io),
            'requests:approve' => $this->decide($io, true),
            'requests:reject' => $this->decide($io, false),
            default => 1,
        };
    }

    /** @return list<array{0: string, 1: string}> */
    public static function help(): array
    {
        return [
            ['requests:list [--type=cookie|beacon] [--status=]', 'List reclassification requests (pending by default)'],
            ['requests:approve --type=cookie --id=N --by=ADDR', 'Approve one: the category changes for every site'],
            ['requests:reject --type=cookie --id=N --by=ADDR', 'Reject one [--note=TEXT]'],
        ];
    }

    private function list(OperatorConsole $io): int
    {
        $type = $io->option('type');
        if ($type !== null && !\in_array($type, self::TYPES, true)) {
            $io->error("Unknown type: {$type}. Use cookie or beacon.");

            return 1;
        }
        $status = $io->option('status') ?? 'pending';
        if (!\in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $io->error("Unknown status: {$status}. Use pending, approved, rejected or all.");

            return 1;
        }
        $filter = $status === 'all' ? null : $status;

        $shown = 0;
        foreach ($type === null ? self::TYPES : [$type] as $t) {
            $items = ($t === 'cookie' ? $this->cookies : $this->beacons)->listRequests($filter, 1, 500)['items'];
            foreach ($items as $r) {
                $subject = $t === 'cookie'
                    ? (string) $r['cookie_name'] . (($r['cookie_domain'] ?? '') !== '' ? ' (' . $r['cookie_domain'] . ')' : '')
                    : (string) $r['beacon_domain'];
                $io->line(sprintf(
                    '%s #%d  %s  %s -> %s  [%s]  %s, %s',
                    $t,
                    (int) $r['id'],
                    $subject,
                    (string) ($r['current_category_slug'] ?? '?'),
                    (string) ($r['requested_slug'] ?? '?'),
                    (string) $r['status'],
                    (string) ($r['site_domain'] ?? '-'),
                    (string) ($r['created_at'] ?? ''),
                ));
                if (($r['reason'] ?? '') !== '') {
                    $io->line('    Reason: ' . str_replace(["\r", "\n"], ' ', (string) $r['reason']));
                }
                ++$shown;
            }
        }

        $io->line($shown === 0 ? "No {$status} requests." : "{$shown} request(s).");

        return 0;
    }

    private function decide(OperatorConsole $io, bool $approve): int
    {
        $type = $io->option('type');
        if (!\in_array($type, self::TYPES, true)) {
            $io->error('Give the request type with --type=cookie or --type=beacon.');

            return 1;
        }
        $id = $io->option('id');
        if ($id === null || !ctype_digit($id)) {
            $io->error('Give the request number with --id=N (see requests:list).');

            return 1;
        }

        $actor = $this->context->actor($io);
        if (!$actor['ok']) {
            $io->error($actor['error']);

            return 1;
        }
        if ($actor['id'] === null) {
            $io->error('Name the deciding administrator with --by=ADDR: the decision is recorded against an account.');

            return 1;
        }

        $service = $type === 'cookie' ? $this->cookies : $this->beacons;
        $result = $approve
            ? $service->approve($actor['id'], (int) $id, 'cli', 'bin/oci ' . $io->command())
            : $service->reject($actor['id'], (int) $id, $io->option('note'), 'cli', 'bin/oci ' . $io->command());

        if (!$result['success']) {
            $io->error(($result['error'] ?? 'The request could not be decided') . " ({$type} request #{$id}).");

            return 1;
        }

        $io->line(ucfirst($type) . " request #{$id} " . ($approve
            ? 'approved: the requested category is now the global classification.'
            : 'rejected.'));

        return 0;
    }
}
