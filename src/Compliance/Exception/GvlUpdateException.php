<?php

declare(strict_types=1);

namespace OCI\Compliance\Exception;

/**
 * Thrown when a Global Vendor List update fails validation.
 *
 * A thrown GvlUpdateException always means nothing was written to disk —
 * validation runs entirely on the decoded in-memory payload, so the
 * previously published GVL remains byte-identical and keeps serving.
 */
final class GvlUpdateException extends \RuntimeException
{
}
