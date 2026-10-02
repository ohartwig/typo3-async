<?php

declare(strict_types=1);

use Koh\Typo3Async\Domain\AsyncMode;
use Koh\Typo3Async\Infrastructure\Image\PregenerateImageVariants;
use Koh\Typo3Async\Infrastructure\Image\QueueCroppedReferences;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;

defined('TYPO3') or exit;

// Route rendered mails to the `mail` queue and image variants to `images` --
// only when the environment says a consumer reads them (AsyncMode). Otherwise
// TYPO3's default `* => default` routing stays in force and everything is sent
// in the request, as before; image variants are then never queued at all.
if (AsyncMode::fromEnvironment()->isEnabled()) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][SendEmailMessage::class] = 'koh_async_mail';
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][PregenerateImageVariants::class] = 'koh_async_images';
}

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['koh_async'] = QueueCroppedReferences::class;
