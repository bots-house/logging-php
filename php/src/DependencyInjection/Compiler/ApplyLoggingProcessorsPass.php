<?php

declare(strict_types=1);

namespace Adheart\Logging\DependencyInjection\Compiler;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class ApplyLoggingProcessorsPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('logging.processor_service_ids')) {
            return;
        }

        /** @var array<int,string> $processorServiceIds */
        $processorServiceIds = $container->getParameter('logging.processor_service_ids');
        if ($processorServiceIds === []) {
            return;
        }

        $loggerIds = $this->collectLoggerDefinitionIds($container);

        foreach ($loggerIds as $loggerId) {
            $definition = $container->getDefinition($loggerId);

            // Monolog\Logger::pushProcessor() unshifts items,
            // so we push in reverse order to preserve configured execution order.
            foreach (array_reverse($processorServiceIds) as $processorServiceId) {
                if (!$this->serviceExists($container, $processorServiceId)) {
                    throw new InvalidArgumentException(sprintf(
                        'Configured logging processor "%s" is not a registered service.',
                        $processorServiceId
                    ));
                }

                if ($this->hasProcessorCall($definition->getMethodCalls(), $processorServiceId)) {
                    continue;
                }

                $definition->addMethodCall('pushProcessor', [new Reference($processorServiceId)]);
            }
        }
    }

    /**
     * @return array<int,string>
     */
    private function collectLoggerDefinitionIds(ContainerBuilder $container): array
    {
        $ids = [];

        foreach (array_keys($container->getDefinitions()) as $id) {
            if ($id === 'monolog.logger' || str_starts_with($id, 'monolog.logger.')) {
                $ids[] = $id;
            }
        }

        foreach ($container->getAliases() as $id => $alias) {
            if ($id !== 'monolog.logger' && !str_starts_with($id, 'monolog.logger.')) {
                continue;
            }

            $targetId = (string)$alias;
            if ($container->hasDefinition($targetId)) {
                $ids[] = $targetId;
            }
        }

        sort($ids);

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int,array{0: string, 1: array<int,mixed>}> $calls
     */
    private function hasProcessorCall(array $calls, string $processorServiceId): bool
    {
        foreach ($calls as $call) {
            if ($call[0] !== 'pushProcessor') {
                continue;
            }

            if (!isset($call[1][0]) || !$call[1][0] instanceof Reference) {
                continue;
            }

            if ((string)$call[1][0] === $processorServiceId) {
                return true;
            }
        }

        return false;
    }

    private function serviceExists(ContainerBuilder $container, string $serviceId): bool
    {
        return $container->hasDefinition($serviceId) || $container->hasAlias($serviceId);
    }
}
