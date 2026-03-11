<?php

declare(strict_types=1);

namespace Tests\Unit\DependencyInjection\Compiler;

use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingFormatterPass;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ApplyLoggingFormatterPassTest extends TestCase
{
    public function testAppliesFormatterToAllFormattableHandlers(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));
        $container->setDefinition('monolog.handler.console', new Definition(StreamHandler::class));
        $container->setDefinition('monolog.handler.no_formatter', new Definition(Logger::class));
        $container->setDefinition('app.logging.formatter', new Definition());

        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter');

        $pass = new ApplyLoggingFormatterPass();
        $pass->process($container);

        $stdoutCalls = $container->getDefinition('monolog.handler.stdout')->getMethodCalls();
        self::assertCount(1, $stdoutCalls);
        self::assertSame('setFormatter', $stdoutCalls[0][0]);
        self::assertSame('app.logging.formatter', (string)$stdoutCalls[0][1][0]);

        $consoleCalls = $container->getDefinition('monolog.handler.console')->getMethodCalls();
        self::assertCount(1, $consoleCalls);
        self::assertSame('setFormatter', $consoleCalls[0][0]);

        $nonFormattableCalls = $container->getDefinition('monolog.handler.no_formatter')->getMethodCalls();
        self::assertSame([], $nonFormattableCalls);
    }

    public function testThrowsOnUnknownFormatterServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.handler.stdout', new Definition(StreamHandler::class));
        $container->setParameter('logging.formatter_service_id', 'app.logging.formatter');

        $pass = new ApplyLoggingFormatterPass();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Configured logging formatter "app.logging.formatter" is not a registered service.'
        );

        $pass->process($container);
    }
}
