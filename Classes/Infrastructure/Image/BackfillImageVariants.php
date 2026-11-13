<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

/**
 * The message: queue the variants of the next batch of images that are
 * already in use, then queue this message again for the batch after it.
 *
 * A pass walks first the files, then the cropped references, each in uid
 * order; the cursor is the last uid of the batch just queued. Carrying the
 * cursor in the message rather than in a table keeps a pass restartable:
 * a parked or lost message stops the pass where it was, and the next change
 * of the profiles (or the command) starts a new one from the beginning, which
 * costs a lookup per variant that already exists and nothing else.
 *
 * `$pass` is the fingerprint of the profiles the pass was started for. A
 * newer pass replaces an older one: the handler drops a message whose
 * fingerprint is no longer the one on record.
 */
final class BackfillImageVariants
{
    public const PHASE_FILES = 'files';

    public const PHASE_REFERENCES = 'references';

    private function __construct(
        public readonly string $pass,
        public readonly string $phase,
        public readonly int $afterUid,
    ) {}

    public static function start(string $pass): self
    {
        return new self($pass, self::PHASE_FILES, 0);
    }

    public function continueAfter(int $uid): self
    {
        return new self($this->pass, $this->phase, $uid);
    }

    public function withReferences(): self
    {
        return new self($this->pass, self::PHASE_REFERENCES, 0);
    }
}
