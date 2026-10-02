<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ExtensionLayoutTest extends TestCase
{
    /**
     * extension:setup copies the defaults of an ext_conf_template.txt into
     * config/system/settings.php. Where the code ships as a read-only image
     * volume, that write fails and the deploy stops in its setup hook -- what
     * 1.1.0 did on 2026-10-02. Settings come from $GLOBALS with defaults in
     * code instead.
     */
    #[Test]
    public function thereIsNoExtConfTemplateForExtensionSetupToWriteBack(): void
    {
        self::assertFileDoesNotExist(\dirname(__DIR__, 2) . '/ext_conf_template.txt');
    }
}
