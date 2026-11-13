<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Messenger\Transports;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Starts a backfill pass when a consumer of `images` starts and the profiles
 * changed since the last pass.
 *
 * The consumer start is the right moment for two reasons: it is the one
 * process that may scan sys_file_processedfile (the learner never runs in a
 * request), and it restarts every hour (--time-limit=3600), which is also how
 * long LearnedImageProfiles keeps what it learned. So a template that starts
 * asking for new instructions has its existing images prepared within about
 * two hours of the first renders that taught the learner -- without a
 * scheduler record and without a deploy hook.
 *
 * Off with `backfillOnProfileChange` = false under
 * $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']; the command
 * koh-async:image-variants:backfill starts a pass by hand either way.
 */
final class BackfillOnProfileChange
{
    public function __construct(
        private readonly ImageVariantsBackfill $backfill,
        private readonly AsyncMode $mode,
        private readonly LoggerInterface $logger,
    ) {}

    #[AsEventListener(identifier: 'koh-async/backfill-on-profile-change')]
    public function __invoke(WorkerStartedEvent $event): void
    {
        if (!$this->mode->isEnabled() || !self::enabled()) {
            return;
        }
        if (!\in_array(Transports::IMAGES, $event->getWorker()->getMetadata()->getTransportNames(), true)) {
            return;
        }
        $pass = $this->backfill->startIfChanged(time());
        if (null !== $pass) {
            $this->logger->info('Image profiles changed, backfill pass {pass} started', ['pass' => $pass]);
        }
    }

    public static function enabled(): bool
    {
        $setting = $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_async']['backfillOnProfileChange'] ?? true;

        return filter_var($setting, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }
}
