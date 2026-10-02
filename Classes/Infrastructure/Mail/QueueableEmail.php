<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Mail;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Core\Mail\FluidEmail;

/**
 * Turns a message into one that survives the queue.
 *
 * A FluidEmail renders lazily, on getBody(), and holds its view and the request
 * to do so; neither can be serialized, and rendering in the consumer would
 * happen without the request the mail was written for (site, language, links).
 * So it is rendered here, in the request, and its result is copied into a plain
 * Email: headers, both bodies and every attachment, whose content Symfony keeps
 * when the message is serialized. Every other message is already plain data and
 * passes through unchanged.
 */
final class QueueableEmail
{
    public static function from(RawMessage $message): RawMessage
    {
        if (!$message instanceof FluidEmail) {
            return $message;
        }

        // Rendering happens here, in the request.
        $message->getBody();

        $email = new Email(clone $message->getHeaders());
        if (null !== $html = $message->getHtmlBody()) {
            $email->html($html, $message->getHtmlCharset() ?? 'utf-8');
        }
        if (null !== $text = $message->getTextBody()) {
            $email->text($text, $message->getTextCharset() ?? 'utf-8');
        }
        foreach ($message->getAttachments() as $attachment) {
            $email->addPart($attachment);
        }

        return $email;
    }
}
