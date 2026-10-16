<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

/**
 * How long a parked message is kept.
 *
 * A message in the failure queue is the whole message: a mail with its
 * recipients, body and attachments, or an image job. It waits there to be
 * looked at, and looking is not deleting -- without a limit a parked
 * password-reset mail stays readable to anyone with database access long
 * after the link expired. So parked messages older than this are pruned by
 * koh-async:failed:prune, which the scheduler runs.
 *
 *   failedRetentionDays  days a parked message is kept; default 14, 0 = for
 *                        ever (then pruning is a manual decision)
 *
 * Under $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'], like the other
 * settings; there is no ext_conf_template.txt, see ExtensionLayoutTest.
 */
final class FailedRetention
{
    public const DEFAULT_DAYS = 14;

    public function __construct(private readonly int $days = self::DEFAULT_DAYS) {}

    public static function fromConfiguration(mixed $days): self
    {
        return new self(is_numeric($days) ? max(0, (int) $days) : self::DEFAULT_DAYS);
    }

    public static function fromGlobals(): self
    {
        return self::fromConfiguration($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['failedRetentionDays'] ?? self::DEFAULT_DAYS);
    }

    public function days(): int
    {
        return $this->days;
    }

    /** The moment before which a parked message is due for pruning, or null when nothing ever is. */
    public function cutoff(\DateTimeImmutable $now): ?\DateTimeImmutable
    {
        return 0 === $this->days ? null : $now->sub(new \DateInterval(\sprintf('P%dD', $this->days)));
    }
}
