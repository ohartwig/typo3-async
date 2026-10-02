<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Domain;

/**
 * Whether work leaves the request at all.
 *
 * Off unless the environment says MESSENGER_ASYNC=1. The switch belongs to the
 * environment, not to the code: a queue nobody reads does not delay a message,
 * it keeps it, so it may only be switched on where a consumer runs. The
 * platform sets the variable together with the consumer and never one without
 * the other.
 */
final class AsyncMode
{
    public const ENVIRONMENT_VARIABLE = 'MESSENGER_ASYNC';

    public function __construct(private readonly bool $enabled) {}

    public static function fromEnvironment(): self
    {
        return new self('1' === getenv(self::ENVIRONMENT_VARIABLE));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
