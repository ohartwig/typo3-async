<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

/**
 * Symfony's PHP serializer, decoding only the classes on the allowlist.
 *
 * The wire format is unchanged, so a message queued by TYPO3's serializer
 * before this one was wired decodes as before -- provided it is made of
 * allowed classes, which every message this extension queues is. A row made
 * of anything else is refused with the class names, before any of its
 * objects exists; the Doctrine receiver then removes the row and the worker
 * stops with the exception, which is the honest reaction to a queue row
 * nobody here wrote.
 */
final class AllowlistedPhpSerializer extends PhpSerializer
{
    public function __construct(private readonly QueueClassAllowlist $allowlist) {}

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? '';
        if (\is_string($body) && '' !== $body) {
            // The parent's own reading of the body: raw, or base64 where it
            // does not end in a closing brace.
            if (!str_ends_with($body, '}')) {
                $body = base64_decode($body, true);
            }
            if (\is_string($body)) {
                $refused = $this->allowlist->refused(stripslashes($body));
                if ([] !== $refused) {
                    throw new MessageDecodingFailedException(\sprintf('Refused to decode a queued message made of classes outside the allowlist: %s. A message of your own needs its class in messageClassPrefixes.', implode(', ', $refused)));
                }
            }
        }

        return parent::decode($encodedEnvelope);
    }
}
