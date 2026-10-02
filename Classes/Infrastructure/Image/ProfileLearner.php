<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use TYPO3\CMS\Core\Imaging\ImageManipulation\Area;

/**
 * Learns image profiles from what the site has already rendered.
 *
 * Every processed file TYPO3 keeps records the instruction array a template
 * built for it, keys in the template's order -- exactly what a profile must
 * be. A configuration used for enough different originals is a size the
 * templates request, and becomes a profile.
 *
 * A stored crop is an absolute area, valid for one reference only. What is
 * learned instead is the crop variant's name: the area is compared with the
 * variants saved on the references of the same file, and every variant that
 * produces exactly that area is credited. Two variants with the same area on
 * one reference produce the same processed file, so crediting both is right.
 * A crop that matches no variant any more (reference deleted, crop changed
 * since) teaches nothing.
 *
 * Pure: the rows and the variant lookup come in, so it is tested without a
 * database.
 */
final class ProfileLearner
{
    /**
     * @param iterable<array{original: int|string, configuration: mixed}> $rows
     * @param \Closure(int, Area): list<string>                           $variantsOf the crop variants of
     *                                                                                the file's references that
     *                                                                                produce this area
     *
     * @return list<array{profile: array<string, mixed>, originals: int, share: float}>
     */
    public function learn(iterable $rows, \Closure $variantsOf, float $minShare, int $minOriginals): array
    {
        $profiles = [];
        $originalsOf = [];
        $allOriginals = [];
        foreach ($rows as $row) {
            $original = (int) $row['original'];
            $configuration = \is_string($row['configuration'])
                ? @unserialize($row['configuration'], ['allowed_classes' => [Area::class]])
                : null;
            if ($original <= 0 || !\is_array($configuration) || [] === $configuration) {
                continue;
            }
            $allOriginals[$original] = true;
            foreach (self::profilesOf($configuration, $original, $variantsOf) as $profile) {
                $key = (string) json_encode($profile);
                $profiles[$key] = $profile;
                $originalsOf[$key][$original] = true;
            }
        }
        $total = \count($allOriginals);
        $learned = [];
        foreach ($originalsOf as $key => $originals) {
            $count = \count($originals);
            $share = $count / $total;
            if ($count >= $minOriginals && $share >= $minShare) {
                $learned[] = ['profile' => $profiles[$key], 'originals' => $count, 'share' => $share];
            }
        }
        usort($learned, static fn(array $a, array $b): int => $b['originals'] <=> $a['originals']);

        return $learned;
    }

    /**
     * @param array<mixed>                      $configuration
     * @param \Closure(int, Area): list<string> $variantsOf
     *
     * @return list<array<string, mixed>>
     */
    private static function profilesOf(array $configuration, int $original, \Closure $variantsOf): array
    {
        $variants = [null];
        foreach ($configuration as $key => $value) {
            if (!\is_string($key)) {
                return [];
            }
            if ('crop' === $key && $value instanceof Area) {
                $variants = $variantsOf($original, $value);
                continue;
            }
            if (null !== $value && !\is_scalar($value)) {
                return [];
            }
        }
        $profiles = [];
        foreach ($variants as $variant) {
            $profile = $configuration;
            if (null !== $variant) {
                // In place: the key's position is part of the checksum.
                $profile['crop'] = $variant;
            }
            $profiles[] = $profile;
        }

        return $profiles;
    }
}
