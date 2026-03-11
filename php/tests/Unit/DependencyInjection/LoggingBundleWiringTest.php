<?php

declare(strict_types=1);

namespace Tests\Unit\DependencyInjection;

use Adheart\Logging\Core\Formatters\SchemaFormatterV1;
use Adheart\Logging\Core\Processors\MessageNormalizerProcessor;
use Adheart\Logging\Core\Processors\TraceContextProcessor;
use Adheart\Logging\DependencyInjection\AdheartLoggingExtension;
use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingFormatterPass;
use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingProcessorsPass;
use Adheart\Logging\Integration\OpenTelemetry\Trace\CfRayTraceContextProvider;
use Adheart\Logging\Integration\OpenTelemetry\Trace\OpenTelemetryTraceContextProvider;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class LoggingBundleWiringTest extends TestCase
{
    public function testAppliesConfiguredFormatterAndProcessorsToMonologServices(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('monolog.logger', new Definition(Logger::class));
        $container->setDefinition('monolog.logger.app', new Definition(Logger::class));
        $container->setDefinition('monolog.handler.main', new Definition(StreamHandler::class));
        $container->setDefinition('monolog.handler.no_formatter', new Definition(Logger::class));
        $container->setDefinition('app.custom_trace_provider', new Definition());

        $extension = new AdheartLoggingExtension();
        $extension->load([
            [
                'processors' => ['message_normalizer'],
                'integrations' => ['custom_trace', 'otel_trace'],
                'aliases' => [
                    'integrations' => [
                        'custom_trace' => [
                            'processors' => ['trace'],
                            'trace_providers' => ['@app.custom_trace_provider'],
                        ],
                    ],
                ],
            ],
        ], $container);

        (new ApplyLoggingProcessorsPass())->process($container);
        (new ApplyLoggingFormatterPass())->process($container);

        $loggerCalls = $container->getDefinition('monolog.logger')->getMethodCalls();
        self::assertSame('pushProcessor', $loggerCalls[0][0]);
        self::assertSame(TraceContextProcessor::class, (string)$loggerCalls[0][1][0]);
        self::assertSame(MessageNormalizerProcessor::class, (string)$loggerCalls[1][1][0]);

        $handlerCalls = $container->getDefinition('monolog.handler.main')->getMethodCalls();
        self::assertSame('setFormatter', $handlerCalls[0][0]);
        self::assertSame(SchemaFormatterV1::class, (string)$handlerCalls[0][1][0]);

        self::assertSame([], $container->getDefinition('monolog.handler.no_formatter')->getMethodCalls());

        /** @var array<int,mixed> $providers */
        $providers = $container->getDefinition(TraceContextProcessor::class)->getArgument('$providers');
        self::assertSame(
            [
                'app.custom_trace_provider',
                OpenTelemetryTraceContextProvider::class,
                CfRayTraceContextProvider::class,
            ],
            array_map(static fn ($provider): string => (string)$provider, $providers)
        );
    }
}
