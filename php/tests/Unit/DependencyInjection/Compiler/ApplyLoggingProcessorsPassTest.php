<?php

declare(strict_types=1);

namespace Tests\Unit\DependencyInjection\Compiler;

use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingProcessorsPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ApplyLoggingProcessorsPassTest extends TestCase
{
    public function testAddsProcessorsToAllMonologLoggersInConfiguredOrder(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.logger', new Definition(\Monolog\Logger::class));
        $container->setDefinition('monolog.logger.app', new Definition(\Monolog\Logger::class));
        $container->setDefinition('app.processor.a', new Definition());
        $container->setDefinition('app.processor.b', new Definition());

        $existing = new Definition(\Monolog\Logger::class);
        $existing->addMethodCall('pushProcessor', [new Reference('app.processor.a')]);
        $container->setDefinition('monolog.logger.billing', $existing);

        $container->setParameter('logging.processor_service_ids', ['app.processor.a', 'app.processor.b']);

        $pass = new ApplyLoggingProcessorsPass();
        $pass->process($container);

        $rootCalls = $container->getDefinition('monolog.logger')->getMethodCalls();
        self::assertSame('pushProcessor', $rootCalls[0][0]);
        self::assertSame('app.processor.b', (string)$rootCalls[0][1][0]);
        self::assertSame('app.processor.a', (string)$rootCalls[1][1][0]);

        $appCalls = $container->getDefinition('monolog.logger.app')->getMethodCalls();
        self::assertCount(2, $appCalls);
        self::assertSame('app.processor.b', (string)$appCalls[0][1][0]);
        self::assertSame('app.processor.a', (string)$appCalls[1][1][0]);

        $billingCalls = $container->getDefinition('monolog.logger.billing')->getMethodCalls();
        self::assertCount(2, $billingCalls);
        self::assertSame('app.processor.a', (string)$billingCalls[0][1][0]);
        self::assertSame('app.processor.b', (string)$billingCalls[1][1][0]);
    }

    public function testThrowsOnUnknownProcessorServiceId(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.logger', new Definition(\Monolog\Logger::class));
        $container->setParameter('logging.processor_service_ids', ['app.missing_processor']);

        $pass = new ApplyLoggingProcessorsPass();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Configured logging processor "app.missing_processor" is not a registered service.'
        );

        $pass->process($container);
    }
}
