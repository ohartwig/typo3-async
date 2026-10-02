<?php

declare(strict_types=1);

namespace Koh\Typo3Async\Tests\Unit\Domain;

use Koh\Typo3Async\Domain\AsyncMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AsyncModeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE);
    }

    #[Test]
    public function itIsOffUnlessTheEnvironmentSaysOne(): void
    {
        putenv(AsyncMode::ENVIRONMENT_VARIABLE);
        self::assertFalse(AsyncMode::fromEnvironment()->isEnabled(), 'absent');

        foreach (['0', 'true', 'yes', ''] as $value) {
            putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=' . $value);
            self::assertFalse(AsyncMode::fromEnvironment()->isEnabled(), var_export($value, true));
        }

        putenv(AsyncMode::ENVIRONMENT_VARIABLE . '=1');
        self::assertTrue(AsyncMode::fromEnvironment()->isEnabled());
    }
}
