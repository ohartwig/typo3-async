<?php

declare(strict_types=1);

use Koh\Typo3Async\Domain\AsyncMode;
use Symfony\Component\Mailer\Messenger\SendEmailMessage;

defined('TYPO3') or exit;

// Route rendered mails to the `mail` queue -- only when the environment says
// a consumer reads it (AsyncMode). Otherwise TYPO3's default `* => default`
// routing stays in force and everything is sent in the request, as before.
if (AsyncMode::fromEnvironment()->isEnabled()) {
    $GLOBALS['TYPO3_CONF_VARS']['SYS']['messenger']['routing'][SendEmailMessage::class] = 'koh_async_mail';
}
