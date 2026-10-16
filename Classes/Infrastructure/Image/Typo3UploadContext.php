<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Infrastructure\Image;

use Koh\Typo3Async\Domain\UploadContext;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ApplicationType;

/**
 * Reads the context from TYPO3's globals, because the FAL events that
 * announce an upload carry no request.
 *
 * Backend means: the current request is a backend request, or a backend user
 * is authenticated -- the latter covers the DataHandler and CLI runs such as
 * an import, which act as a backend user without a backend request. A
 * frontend request with a logged-in backend user (an editor previewing the
 * site) counts as backend too; the upload is still the editor's.
 */
final class Typo3UploadContext implements UploadContext
{
    public function isBackend(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request instanceof ServerRequestInterface
            && \is_int($request->getAttribute('applicationType'))
            && ApplicationType::fromRequest($request)->isBackend()
        ) {
            return true;
        }
        $user = $GLOBALS['BE_USER'] ?? null;

        return $user instanceof BackendUserAuthentication && ($user->getUserId() ?? 0) > 0;
    }
}
