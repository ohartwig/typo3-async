<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Domain;

/**
 * Where an upload comes from, as far as queuing is concerned: from the
 * backend, that is from an editor who is logged in, or from anywhere else --
 * a frontend form, an anonymous API call, a script without a user.
 *
 * The distinction decides whether an upload is worth ImageMagick work in the
 * consumer. An editor's upload will be rendered by the site's templates; a
 * stranger's upload is work nobody asked for, on the consumer that also
 * delivers the mail.
 */
interface UploadContext
{
    public function isBackend(): bool;
}
