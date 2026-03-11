<?php

declare(strict_types=1);

namespace Tests\Unit;

use Adheart\Logging\DependencyInjection\AdheartLoggingExtension;
use Adheart\Logging\LoggingBundle;
use PHPUnit\Framework\TestCase;

final class LoggingBundleTest extends TestCase
{
    public function testReturnsAdheartLoggingExtensionInstance(): void
    {
        $bundle = new LoggingBundle();

        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(AdheartLoggingExtension::class, $extension);
    }
}
