<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Messenger;

/**
 * The names of the transports this extension declares (Configuration/
 * Services.yaml), for the routing of other extensions.
 *
 * An extension that routes a message of its own here checks that the
 * constant it routes to is defined, not only that this extension is
 * installed: a transport exists from the release that added its constant,
 * and routing to a name the container does not know fails every dispatch.
 *
 *   MAIL    queue `mail`, rendered mails (routed here by this extension)
 *   IMAGES  queue `images`, image variants (routed here by this extension)
 *   CACHE   queue `cache`, general purpose: short, idempotent work another
 *           extension takes out of the request, such as edge cache purges.
 *           Nothing is routed here by this extension.
 *   FAILED  queue `failed`, messages that used up their retries
 */
final class Transports
{
    public const MAIL = 'koh_async_mail';

    public const IMAGES = 'koh_async_images';

    public const CACHE = 'koh_async_cache';

    public const FAILED = 'koh_async_failed';
}
