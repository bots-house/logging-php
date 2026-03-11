<?php

declare(strict_types=1);

namespace Adheart\Logging\DependencyInjection;

use Adheart\Logging\Command\ScanCommand;
use Adheart\Logging\Core\Formatters\SchemaFormatterV1;
use Adheart\Logging\Core\Processors\MessageNormalizerProcessor;
use Adheart\Logging\Core\Processors\TraceContextProcessor;
use Adheart\Logging\Inventory\Extractor\LogUsageExtractor;
use Adheart\Logging\Inventory\Filter\LogUsageFilterSorter;
use Adheart\Logging\Inventory\LoggerCatalogProvider;
use Adheart\Logging\Inventory\Renderer\LogUsageRenderer;
use Adheart\Logging\Inventory\Scanner\LogUsageScanner;
use Adheart\Logging\Inventory\ScanQueryFactory;
use Adheart\Logging\Integration\OpenTelemetry\Trace\CfRayTraceContextProvider;
use Adheart\Logging\Integration\OpenTelemetry\Trace\OpenTelemetryTraceContextProvider;
use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class AdheartLoggingExtension extends Extension
{
    #[\Override]
    public function getAlias(): string
    {
        return 'logging';
    }

    /**
     * @param array<mixed> $configs
     */
    #[\Override]
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        /** @var array{
         *   processors: array<int,string>,
         *   formatter: array{schema_version: string, service_name: string|null, service_version: string|null},
         *   integrations: array<int,string>,
         *   aliases: array{
         *     processors: array<string,string>,
         *     trace_providers: array<string,string>,
         *     integrations: array<string,array{processors: array<int,string>, trace_providers: array<int,string>}>
         *   }
         * } $config
         */
        $config = $this->processConfiguration($configuration, $configs);

        $this->registerBuiltinServices($container, $config);
        $this->registerLoggingConfiguration($container, $config);

        if (!$this->canRegisterScanCommand()) {
            return;
        }

        $container->register(LogUsageExtractor::class, LogUsageExtractor::class);
        $container->register(LogUsageFilterSorter::class, LogUsageFilterSorter::class);
        $container->register(LogUsageRenderer::class, LogUsageRenderer::class);
        $container->register(ScanQueryFactory::class, ScanQueryFactory::class);

        $container->register(LogUsageScanner::class, LogUsageScanner::class)
            ->setArgument('$projectDir', '%kernel.project_dir%')
            ->setArgument('$extractor', new Reference(LogUsageExtractor::class));

        $container->register(LoggerCatalogProvider::class, LoggerCatalogProvider::class)
            ->setArgument('$loggerServices', []);

        $container->register(ScanCommand::class, ScanCommand::class)
            ->setAutowired(true)
            ->addTag('console.command');
    }

    private function canRegisterScanCommand(): bool
    {
        return class_exists(\Symfony\Component\Console\Command\Command::class)
            && interface_exists(\Symfony\Component\DependencyInjection\ContainerInterface::class)
            && class_exists(\PhpParser\ParserFactory::class);
    }

    /**
     * @param array{
     *   processors: array<int,string>,
     *   formatter: array{schema_version: string, service_name: string|null, service_version: string|null},
     *   integrations: array<int,string>,
     *   aliases: array{
     *     processors: array<string,string>,
     *     trace_providers: array<string,string>,
     *     integrations: array<string,array{processors: array<int,string>, trace_providers: array<int,string>}>
     *   }
     * } $config
     */
    private function registerLoggingConfiguration(ContainerBuilder $container, array $config): void
    {
        $processorAliases = $this->builtinProcessorAliases();
        foreach ($config['aliases']['processors'] as $alias => $serviceId) {
            $processorAliases[$alias] = $this->normalizeServiceId($serviceId);
        }

        $traceProviderAliases = $this->builtinTraceProviderAliases();
        foreach ($config['aliases']['trace_providers'] as $alias => $serviceId) {
            $traceProviderAliases[$alias] = $this->normalizeServiceId($serviceId);
        }

        $integrations = $this->builtinIntegrations();
        foreach ($config['aliases']['integrations'] as $alias => $definition) {
            $integrations[$alias] = $definition;
        }

        $selectedProcessors = $config['processors'];
        $selectedTraceProviders = [];

        foreach ($config['integrations'] as $integrationAliasRaw) {
            $integrationAlias = trim($integrationAliasRaw);
            if ($integrationAlias === '') {
                throw new InvalidArgumentException('Empty logging integration value is not allowed.');
            }

            if (!isset($integrations[$integrationAlias])) {
                $available = array_keys($integrations);
                sort($available);

                throw new InvalidArgumentException(sprintf(
                    'Unknown logging integration alias "%s". Available aliases: %s',
                    $integrationAlias,
                    implode(', ', $available)
                ));
            }

            foreach ($integrations[$integrationAlias]['processors'] as $processorAlias) {
                $selectedProcessors[] = $this->resolveAliasOrServiceId(
                    $processorAlias,
                    $processorAliases,
                    'processor'
                );
            }

            foreach ($integrations[$integrationAlias]['trace_providers'] as $providerAlias) {
                $selectedTraceProviders[] = $this->resolveAliasOrServiceId(
                    $providerAlias,
                    $traceProviderAliases,
                    'trace provider'
                );
            }
        }

        $processorServiceIds = [];
        foreach ($selectedProcessors as $processor) {
            $processorServiceIds[] = $this->resolveAliasOrServiceId($processor, $processorAliases, 'processor');
        }

        $traceProviderServiceIds = [];
        foreach ($selectedTraceProviders as $provider) {
            $traceProviderServiceIds[] = $this->resolveAliasOrServiceId(
                $provider,
                $traceProviderAliases,
                'trace provider'
            );
        }

        $processorServiceIds = $this->uniqueValues($processorServiceIds);
        $traceProviderServiceIds = $this->uniqueValues($traceProviderServiceIds);

        if ($container->hasDefinition(TraceContextProcessor::class)) {
            $references = [];
            foreach ($traceProviderServiceIds as $serviceId) {
                $references[] = new Reference($serviceId, ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE);
            }

            $container->getDefinition(TraceContextProcessor::class)
                ->setArgument('$providers', $references);
        }

        $container->setParameter('logging.processor_service_ids', $processorServiceIds);
        $container->setParameter('logging.trace_provider_service_ids', $traceProviderServiceIds);
        $container->setParameter('logging.formatter_service_id', SchemaFormatterV1::class);
    }

    /**
     * @param array<string,mixed> $config
     */
    private function registerBuiltinServices(ContainerBuilder $container, array $config): void
    {
        $container->register(SchemaFormatterV1::class, SchemaFormatterV1::class)
            ->setAutowired(true)
            ->setArgument('$schemaVersion', $config['formatter']['schema_version'])
            ->setArgument('$serviceName', $config['formatter']['service_name'])
            ->setArgument('$serviceVersion', $config['formatter']['service_version'])
            ->setAutoconfigured(false);

        $container->register(MessageNormalizerProcessor::class, MessageNormalizerProcessor::class)
            ->setAutowired(true)
            ->setAutoconfigured(false);

        $container->register(TraceContextProcessor::class, TraceContextProcessor::class)
            ->setAutowired(true)
            ->setAutoconfigured(false);

        $container->register(OpenTelemetryTraceContextProvider::class, OpenTelemetryTraceContextProvider::class)
            ->setAutowired(true)
            ->setAutoconfigured(false);

        $container->register(CfRayTraceContextProvider::class, CfRayTraceContextProvider::class)
            ->setAutowired(true)
            ->setAutoconfigured(false);
    }

    /**
     * @return array<string,string>
     */
    private function builtinProcessorAliases(): array
    {
        return [
            'message_normalizer' => MessageNormalizerProcessor::class,
            'trace' => TraceContextProcessor::class,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function builtinTraceProviderAliases(): array
    {
        return [
            'otel' => OpenTelemetryTraceContextProvider::class,
            'cf_ray' => CfRayTraceContextProvider::class,
        ];
    }

    /**
     * @return array<string,array{processors: array<int,string>, trace_providers: array<int,string>}>
     */
    private function builtinIntegrations(): array
    {
        return [
            'otel_trace' => [
                'processors' => ['trace'],
                'trace_providers' => ['otel', 'cf_ray'],
            ],
        ];
    }

    /**
     * @param array<string,string> $aliasMap
     */
    private function resolveAliasOrServiceId(string $value, array $aliasMap, string $type): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('Empty logging %s value is not allowed.', $type));
        }

        if (str_starts_with($value, '@')) {
            return substr($value, 1);
        }

        if (isset($aliasMap[$value])) {
            return $aliasMap[$value];
        }

        return $value;
    }

    private function normalizeServiceId(string $serviceId): string
    {
        $serviceId = trim($serviceId);
        if ($serviceId === '') {
            throw new InvalidArgumentException('Empty logging alias service id is not allowed.');
        }

        return str_starts_with($serviceId, '@') ? substr($serviceId, 1) : $serviceId;
    }

    /**
     * @param array<int,string> $values
     *
     * @return array<int,string>
     */
    private function uniqueValues(array $values): array
    {
        return array_values(array_unique(array_map('trim', $values)));
    }
}
