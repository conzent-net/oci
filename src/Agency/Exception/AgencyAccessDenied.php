<?php

declare(strict_types=1);

namespace OCI\Agency\Exception;

/**
 * An agency asked for something outside its own client book.
 *
 * Thrown rather than returned so it cannot be ignored by a caller that forgot
 * to check a return value — the whole point of routing authorization through
 * {@see \OCI\Agency\Service\AgencyAccessService} is that skipping the check has
 * to be impossible rather than merely discouraged.
 *
 * The message is deliberately identical whether the target does not exist or
 * belongs to somebody else. Distinguishing them turns any agency account into
 * an oracle for "does site 4211 exist", which is a small leak that costs
 * nothing to close here and is awkward to close later.
 */
final class AgencyAccessDenied extends \RuntimeException
{
    public static function site(int $siteId): self
    {
        return new self(sprintf('Site %d is not managed by this agency.', $siteId));
    }

    public static function client(int $customerUserId): self
    {
        return new self(sprintf('Account %d is not a client of this agency.', $customerUserId));
    }

    public static function notAnAgency(): self
    {
        return new self('This account is not an approved agency.');
    }
}
