<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Imaging\ImageManipulation\CropVariantCollection;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Produces the configured variants in the consumer, through TYPO3's own
 * File::process() -- the call Fluid's ImageViewHelper ends in, so the same
 * instructions land on the same processed file and the page finds it ready
 * instead of rendering it on the first view.
 *
 * For a reference the crop is built the way the ImageViewHelper builds it:
 * the named crop variant of the reference's `crop`, made absolute on the
 * reference, or null when that variant is empty -- and written into the
 * profile's own `crop` key, so the key keeps its position in the array and
 * with it the checksum.
 *
 * A record deleted before its message is handled is not an error: there is
 * nothing left to prepare. Any other failure propagates, so the worker
 * retries and finally parks the message.
 */
final class PregenerateImageVariantsHandler
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ImageProfiles $profiles,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PregenerateImageVariants $message): void
    {
        if (null !== $message->fileUid) {
            $this->forFile($message->fileUid);
        }
        if (null !== $message->referenceUid) {
            $this->forReference($message->referenceUid);
        }
    }

    private function forFile(int $uid): void
    {
        try {
            $file = $this->resourceFactory->getFileObject($uid);
        } catch (FileDoesNotExistException) {
            $this->logger->info('File {uid} is gone, no variants to produce', ['uid' => $uid]);

            return;
        }
        if ($file->isMissing()) {
            return;
        }
        foreach ($this->profiles->forFiles() as $instructions) {
            $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, $instructions);
        }
    }

    private function forReference(int $uid): void
    {
        try {
            $reference = $this->resourceFactory->getFileReferenceObject($uid);
        } catch (ResourceDoesNotExistException) {
            $this->logger->info('File reference {uid} is gone, no variants to produce', ['uid' => $uid]);

            return;
        }
        $file = $reference->getOriginalFile();
        if ($file->isMissing()) {
            return;
        }
        $crops = CropVariantCollection::create((string) $reference->getProperty('crop'));
        foreach ($this->profiles->forReferences() as $profile) {
            $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, self::instructions($profile, $crops, $reference));
        }
    }

    /**
     * @param array<string, mixed> $profile
     *
     * @return array<string, mixed>
     */
    public static function instructions(array $profile, CropVariantCollection $crops, FileInterface $image): array
    {
        $area = $crops->getCropArea((string) $profile['crop']);
        $profile['crop'] = $area->isEmpty() ? null : $area->makeAbsoluteBasedOnFile($image);

        return $profile;
    }
}
