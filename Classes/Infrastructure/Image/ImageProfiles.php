<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

/**
 * The processing instructions to produce right after an upload or a crop
 * change, exactly as the site's templates request them.
 *
 * "Exactly" is the whole contract. TYPO3 recognises a processed file by a
 * checksum over its instructions: a variant made from {"width":640} is found
 * again by a template asking for {"width":640} and by nothing else -- not by
 * the same width with a null maxHeight beside it, not by the same keys in
 * another order. (Integer strings are the one thing TYPO3 normalises: "640"
 * and 640 meet.) So profiles are not designed, they are taken from what the
 * site really requested: learned from sys_file_processedfile by default
 * (LearnedImageProfiles), or listed by hand.
 *
 * Two kinds of profile, told apart by `crop`:
 *
 *   {"width": 640, "fileExtension": "avif"}
 *   {"width": 1280, ..., "maxHeight": null, "crop": null}
 *       a file profile (no crop, or crop null), produced for the file itself
 *       on upload and replace;
 *   {"width": 1280, ..., "maxHeight": null, "crop": "default"}
 *       a reference profile, produced for a file reference whose crop
 *       changed. The string names the crop variant the template renders; the
 *       handler replaces it, in place, with the absolute crop area, as Fluid's
 *       ImageViewHelper builds it.
 *
 * Two settings under $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'],
 * typically from config/system/additional.php:
 *
 *   learnImageProfiles  percent of the originals a configuration must have
 *                       been used for to be learned; default 10, 0 = off
 *   imageProfiles       a list -- PHP array or JSON string -- produced in
 *                       addition to the learned ones; default empty
 *
 * There is deliberately no ext_conf_template.txt, see ExtensionLayoutTest.
 */
final class ImageProfiles
{
    public const DEFAULT_LEARN_PERCENT = 10;

    /**
     * @param list<array<string, mixed>> $profiles
     */
    public function __construct(
        private readonly array $profiles,
        private readonly float $learnShare = 0.0,
    ) {}

    public static function fromConfiguration(mixed $raw, mixed $learnPercent = 0): self
    {
        return new self(self::listed($raw), self::share($learnPercent));
    }

    private static function share(mixed $percent): float
    {
        if (!is_numeric($percent)) {
            return self::DEFAULT_LEARN_PERCENT / 100;
        }

        return max(0.0, min(100.0, (float) $percent)) / 100;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listed(mixed $raw): array
    {
        if (\is_string($raw)) {
            if ('' === trim($raw)) {
                return [];
            }
            try {
                $raw = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return [];
            }
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            return [];
        }
        $profiles = [];
        foreach ($raw as $profile) {
            if (!\is_array($profile) || [] === $profile || array_is_list($profile)) {
                continue;
            }
            if (null !== ($profile['crop'] ?? null) && !\is_string($profile['crop'])) {
                continue;
            }
            $profiles[] = $profile;
        }

        return $profiles;
    }

    public static function fromGlobals(): self
    {
        $settings = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async'] ?? [];

        return self::fromConfiguration(
            $settings['imageProfiles'] ?? '',
            $settings['learnImageProfiles'] ?? self::DEFAULT_LEARN_PERCENT,
        );
    }

    public function learnShare(): float
    {
        return $this->learnShare;
    }

    public function learns(): bool
    {
        return $this->learnShare > 0.0;
    }

    /** Whether an upload is worth a message at all. Decided without a database read. */
    public function queuesFiles(): bool
    {
        return $this->learns() || [] !== $this->forFiles();
    }

    /** Whether a saved crop is worth a message at all. Decided without a database read. */
    public function queuesReferences(): bool
    {
        return $this->learns() || $this->hasReferenceProfiles();
    }

    /**
     * The listed profiles plus the learned ones, each once.
     *
     * @param list<array<string, mixed>> $learned
     */
    public function withLearned(array $learned): self
    {
        $merged = [];
        foreach ([...$this->profiles, ...$learned] as $profile) {
            $merged[(string) json_encode($profile)] = $profile;
        }

        return new self(array_values($merged), $this->learnShare);
    }

    public function isEmpty(): bool
    {
        return [] === $this->profiles;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forFiles(): array
    {
        return array_values(array_filter(
            $this->profiles,
            static fn(array $profile): bool => !self::isReferenceProfile($profile),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forReferences(): array
    {
        return array_values(array_filter(
            $this->profiles,
            static fn(array $profile): bool => self::isReferenceProfile($profile),
        ));
    }

    public function hasReferenceProfiles(): bool
    {
        return [] !== $this->forReferences();
    }

    /**
     * @param array<string, mixed> $profile
     */
    private static function isReferenceProfile(array $profile): bool
    {
        return \is_string($profile['crop'] ?? null);
    }
}
