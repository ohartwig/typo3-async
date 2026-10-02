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
 * and 640 meet.) So the profiles are copied from what the site really
 * requested, which the command koh-async:image-profiles:suggest reads out of
 * sys_file_processedfile, keys in their stored order.
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
 * Configured as a list -- PHP array or JSON string -- in
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['imageProfiles'],
 * typically from config/system/additional.php. Empty or invalid means off:
 * nothing is queued. There is deliberately no ext_conf_template.txt, see
 * ExtensionLayoutTest.
 */
final class ImageProfiles
{
    /**
     * @param list<array<string, mixed>> $profiles
     */
    public function __construct(private readonly array $profiles) {}

    public static function fromConfiguration(mixed $raw): self
    {
        if (\is_string($raw)) {
            if ('' === trim($raw)) {
                return new self([]);
            }
            try {
                $raw = json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return new self([]);
            }
        }
        if (!\is_array($raw) || !array_is_list($raw)) {
            return new self([]);
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

        return new self($profiles);
    }

    public static function fromGlobals(): self
    {
        return self::fromConfiguration($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['imageProfiles'] ?? '');
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
