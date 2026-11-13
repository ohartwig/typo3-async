<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use Symfony\Component\Messenger\MessageBusInterface;
use TYPO3\CMS\Core\Registry;

/**
 * Starts a backfill pass: the variants of the images already in use, for the
 * profiles in force now.
 *
 * The upload path prepares an image once, with the profiles of that moment.
 * When the profiles change afterwards -- a template asks for another width,
 * or for AVIF beside WebP, and the learner picks it up -- every image uploaded
 * before is rendered with the new instructions by the first visitor of each
 * page, in the request: measured at 1.4 s median and 7 s p95 per render that
 * produced a variant set (2026-11-13, an S3 storage). A pass moves that work
 * to the consumer.
 *
 * A pass is identified by a fingerprint of the profiles. The fingerprint of
 * the last pass started is kept in the registry; startIfChanged() starts one
 * only when the profiles differ from it, so the consumer can call it on every
 * start without repeating a finished pass. Profiles that move in and out of
 * the learned set start a pass each time they move; it finds most variants in
 * place and costs a lookup for each.
 */
final class ImageVariantsBackfill
{
    public const REGISTRY_NAMESPACE = 'koh_async';

    public const REGISTRY_KEY = 'imageVariantsBackfillPass';

    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly ImageProfiles $configured,
        private readonly LearnedImageProfiles $learned,
        private readonly Registry $registry,
    ) {}

    /** The profiles a variant is produced for now: listed plus learned. */
    public function profiles(int $now): ImageProfiles
    {
        return $this->configured->learns()
            ? $this->configured->withLearned(array_column($this->learned->learn($this->configured->learnShare(), $now), 'profile'))
            : $this->configured;
    }

    public static function fingerprint(ImageProfiles $profiles): string
    {
        return hash('sha256', json_encode([$profiles->forFiles(), $profiles->forReferences()], \JSON_THROW_ON_ERROR));
    }

    public function currentPass(): ?string
    {
        $pass = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);

        return \is_string($pass) ? $pass : null;
    }

    /**
     * Starts a pass for the current profiles, unless the last pass was
     * started for the same ones. Returns the fingerprint of the pass started,
     * or null.
     */
    public function startIfChanged(int $now): ?string
    {
        $profiles = $this->profiles($now);
        if ($profiles->isEmpty()) {
            return null;
        }
        $pass = self::fingerprint($profiles);
        if ($pass === $this->currentPass()) {
            return null;
        }

        return $this->startPass($pass);
    }

    /** Starts a pass for the current profiles whatever ran before. */
    public function start(int $now): ?string
    {
        $profiles = $this->profiles($now);
        if ($profiles->isEmpty()) {
            return null;
        }

        // A forced pass gets its own id, so it replaces a pass still running
        // for the same profiles instead of running beside it.
        return $this->startPass(self::fingerprint($profiles) . '@' . $now);
    }

    private function startPass(string $pass): string
    {
        // Recorded before the first message: a pass already under way for
        // older profiles sees the new id and stops (BackfillImageVariantsHandler).
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $pass);
        $this->bus->dispatch(BackfillImageVariants::start($pass));

        return $pass;
    }
}
