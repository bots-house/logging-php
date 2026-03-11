<?php

declare(strict_types=1);

namespace Tests\Unit\DependencyInjection;

use Adheart\Logging\Core\Formatters\SchemaFormatterV1;
use Adheart\Logging\Core\Processors\MessageNormalizerProcessor;
use Adheart\Logging\Core\Processors\TraceContextProcessor;
use Adheart\Logging\DependencyInjection\AdheartLoggingExtension;
use Adheart\Logging\Integration\OpenTelemetry\Trace\CfRayTraceContextProvider;
use Adheart\Logging\Integration\OpenTelemetry\Trace\OpenTelemetryTraceContextProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class AdheartLoggingExtensionTest extends TestCase
{
    public function testResolvesBuiltInAliasesAndIntegrations(): void
    {
        $container = new ContainerBuilder();
        $extension = new AdheartLoggingExtension();

        $extension->load([
            [
                'processors' => ['message_normalizer', 'trace'],
                'formatter' => [
                    'schema_version' => '1.0',
                    'service_name' => 'adheart',
                    'service_version' => 'prod-1.2.3',
                ],
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

        /** @var array<int,string> $processorServiceIds */
        $processorServiceIds = $container->getParameter('logging.processor_service_ids');
        self::assertSame(
            [MessageNormalizerProcessor::class, TraceContextProcessor::class],
            $processorServiceIds
        );

        self::assertSame(
            SchemaFormatterV1::class,
            $container->getParameter('logging.formatter_service_id')
        );
        self::assertSame(
            'adheart',
            $container->getDefinition(SchemaFormatterV1::class)->getArgument('$serviceName')
        );

        /** @var array<int,mixed> $providers */
        $providers = $container->getDefinition(TraceContextProcessor::class)->getArgument('$providers');
        self::assertCount(3, $providers);
        self::assertSame(
            ['app.custom_trace_provider', OpenTelemetryTraceContextProvider::class, CfRayTraceContextProvider::class],
            array_map(static fn ($provider): string => (string)$provider, $providers)
        );
    }

    public function testSupportsCustomAliasesForServicesOutsideBundle(): void
    {
        $container = new ContainerBuilder();
        $extension = new AdheartLoggingExtension();

        $extension->load([
            [
                'processors' => ['custom_processor'],
                'formatter' => [
                    'schema_version' => '1.0',
                    'service_name' => null,
                    'service_version' => null,
                ],
                'aliases' => [
                    'processors' => ['custom_processor' => '@app.custom_processor'],
                ],
            ],
        ], $container);

        /** @var array<int,string> $processorServiceIds */
        $processorServiceIds = $container->getParameter('logging.processor_service_ids');
        self::assertSame(['app.custom_processor'], $processorServiceIds);
        self::assertSame(SchemaFormatterV1::class, $container->getParameter('logging.formatter_service_id'));
    }

    public function testThrowsOnUnknownIntegrationAlias(): void
    {
        $container = new ContainerBuilder();
        $extension = new AdheartLoggingExtension();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown logging integration alias "unknown".');
        $this->expectExceptionMessage('Available aliases:');

        $extension->load([
            [
                'integrations' => ['unknown'],
            ],
        ], $container);
    }

    public function testRejectsLegacyTopLevelTraceProvidersNode(): void
    {
        $container = new ContainerBuilder();
        $extension = new AdheartLoggingExtension();

        $this->expectException(InvalidConfigurationException::class);

        $extension->load([
            [
                'trace_providers' => ['otel'],
            ],
        ], $container);
    }
}
