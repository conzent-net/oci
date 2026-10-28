<?php

declare(strict_types=1);

namespace OCI\Banner\Service;

use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;

/**
 * Background regeneration of `script.js`, with two priorities.
 *
 * Regenerating one site's bundle takes seconds, not milliseconds — measured at
 * 2.8s per site on the development stack. An agency accepting an invite with
 * twenty client sites, or an admin suspending an agency with forty, cannot
 * wait for that inside a web request: nothing times out (`max_execution_time`
 * is 0 and nginx allows 600s) but the person sits on a spinner for a minute
 * for something they never asked to watch.
 *
 * ## Why its own queue, and why two of them
 *
 * `bin/oci queue:work` already drains scans, backups, restores and exports in
 * one loop — but a scan can hold that loop for minutes, and a revoked agency's
 * branding must not stay live on a client's site because somebody else's scan
 * is running. So this is a separate pair of Redis lists that BOTH workers
 * drain: `queue:work` takes a time-boxed bite each tick, and `queue:work-scripts`
 * is a dedicated worker that blocks on nothing but these lists. Run the second
 * one when the first is busy; the job is safe to process from either.
 *
 * Within the queue, `high` is checked before `normal` on every pop. High is
 * for transitions that change what a visitor is entitled to see — a link
 * ending, an agency suspended, a revert somebody is waiting on. Normal is
 * bulk: a template applied to forty sites, where the fortieth arriving thirty
 * seconds after the first is fine.
 *
 * ## Dedupe, and why it is allowed to be imperfect
 *
 * A site queued twice regenerates twice, and the second run reads the same
 * database state as the first — wasteful, never wrong. So the pending set only
 * has to be good enough to stop a link change queuing the same forty sites
 * three times over, not perfect. A `high` request always queues even if the
 * site is already pending in `normal`, because "waits behind 200 template
 * sites" is the failure the priority exists to prevent.
 *
 * ## Failure
 *
 * A job that throws is retried twice, at normal priority, then logged as an
 * error naming the site. Until somebody runs `scripts:regenerate` that site
 * serves its previous bundle, and the log says so — silently dropping it is
 * how a revoked agency's branding stays live indefinitely.
 */
final class ScriptRegenerationJobService implements SiteScriptQueueInterface
{
    private const QUEUE_HIGH = 'oci:scripts:queue:high';
    private const QUEUE_NORMAL = 'oci:scripts:queue:normal';
    private const PENDING_SET = 'oci:scripts:pending';
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly RedisClient $redis,
        private readonly ScriptGenerationService $scripts,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Queue regeneration for these sites.
     *
     * @param array<int, int> $siteIds
     * @param string          $reason  free text for the log, e.g. "agency 12 suspended"
     *
     * @return int jobs queued (after dedupe)
     */
    public function enqueue(array $siteIds, string $priority = self::PRIORITY_NORMAL, string $reason = ''): int
    {
        $priority = $priority === self::PRIORITY_HIGH ? self::PRIORITY_HIGH : self::PRIORITY_NORMAL;
        $list = $priority === self::PRIORITY_HIGH ? self::QUEUE_HIGH : self::QUEUE_NORMAL;
        $queued = 0;

        foreach (array_unique(array_map('intval', $siteIds)) as $siteId) {
            if ($siteId <= 0) {
                continue;
            }

            $isNew = (int) $this->redis->sadd(self::PENDING_SET, [(string) $siteId]) === 1;

            // Normal-priority work already pending is enough; a high-priority
            // request queues regardless, so it cannot be stuck behind bulk.
            if (!$isNew && $priority === self::PRIORITY_NORMAL) {
                continue;
            }

            $this->redis->lpush($list, [$this->encode($siteId, 1, $reason)]);
            ++$queued;
        }

        if ($queued > 0) {
            $this->logger->info(sprintf(
                'Queued script regeneration for %d site(s) at %s priority%s',
                $queued,
                $priority,
                $reason !== '' ? ' (' . $reason . ')' : '',
            ));
        }

        return $queued;
    }

    /**
     * One worker step, non-blocking. Returns true when a job was processed.
     *
     * Called from the shared `queue:work` loop, which must not block here: it
     * has other queues to service.
     */
    public function processNext(): bool
    {
        $raw = $this->redis->rpop(self::QUEUE_HIGH);

        if (!\is_string($raw) || $raw === '') {
            $raw = $this->redis->rpop(self::QUEUE_NORMAL);
        }

        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        $this->run($raw);

        return true;
    }

    /**
     * Process jobs for up to `$budgetSeconds`, non-blocking. Returns how many.
     *
     * The shared worker calls this once per tick so a queued site starts
     * within seconds of a scan finishing rather than one-per-tick behind the
     * scan pop's five-second timeout.
     */
    public function drain(int $budgetSeconds = 10): int
    {
        $deadline = microtime(true) + max(1, $budgetSeconds);
        $done = 0;

        while (microtime(true) < $deadline && $this->processNext()) {
            ++$done;
        }

        return $done;
    }

    /**
     * Blocking pop across both lists, high first. For the dedicated worker.
     *
     * Redis BRPOP checks the keys in the order given and returns from the
     * first non-empty one, which is exactly the priority rule with no code
     * around it.
     */
    public function processBlocking(int $timeoutSeconds = 5): bool
    {
        $result = $this->redis->brpop([self::QUEUE_HIGH, self::QUEUE_NORMAL], $timeoutSeconds);

        if (!\is_array($result) || !isset($result[1]) || !\is_string($result[1])) {
            return false;
        }

        $this->run($result[1]);

        return true;
    }

    /** @return array{high: int, normal: int, pending: int} */
    public function depth(): array
    {
        return [
            'high' => (int) $this->redis->llen(self::QUEUE_HIGH),
            'normal' => (int) $this->redis->llen(self::QUEUE_NORMAL),
            'pending' => (int) $this->redis->scard(self::PENDING_SET),
        ];
    }

    private function run(string $raw): void
    {
        try {
            /** @var array{site_id?: int, attempt?: int, reason?: string} $job */
            $job = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->logger->warning('Discarded malformed script regeneration job', ['payload' => mb_substr($raw, 0, 200)]);

            return;
        }

        $siteId = (int) ($job['site_id'] ?? 0);
        $attempt = max(1, (int) ($job['attempt'] ?? 1));
        $reason = (string) ($job['reason'] ?? '');

        if ($siteId <= 0) {
            return;
        }

        // Cleared BEFORE the work, so a change that lands while this run is in
        // progress queues a fresh job rather than being swallowed as "already
        // pending". The cost is at most one duplicate regeneration.
        $this->redis->srem(self::PENDING_SET, [(string) $siteId]);

        try {
            $started = microtime(true);
            $this->scripts->generate($siteId);
            $this->logger->info(sprintf(
                'Regenerated script for site %d in %.1fs%s',
                $siteId,
                microtime(true) - $started,
                $reason !== '' ? ' (' . $reason . ')' : '',
            ));
        } catch (\Throwable $e) {
            if ($attempt < self::MAX_ATTEMPTS) {
                $this->redis->sadd(self::PENDING_SET, [(string) $siteId]);
                $this->redis->lpush(self::QUEUE_NORMAL, [$this->encode($siteId, $attempt + 1, $reason)]);
                $this->logger->warning(sprintf(
                    'Script regeneration for site %d failed (attempt %d of %d), requeued: %s',
                    $siteId,
                    $attempt,
                    self::MAX_ATTEMPTS,
                    $e->getMessage(),
                ));

                return;
            }

            $this->logger->error(sprintf(
                'Script regeneration for site %d failed %d times and was dropped. That site is serving its previous '
                . 'bundle until bin/oci scripts:regenerate is run. Last error: %s',
                $siteId,
                self::MAX_ATTEMPTS,
                $e->getMessage(),
            ));
        }
    }

    private function encode(int $siteId, int $attempt, string $reason): string
    {
        return (string) json_encode(
            ['site_id' => $siteId, 'attempt' => $attempt, 'reason' => mb_substr($reason, 0, 120)],
            JSON_THROW_ON_ERROR,
        );
    }
}
