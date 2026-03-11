<?php

declare(strict_types=1);

namespace Adheart\Logging\DependencyInjection\Compiler;

use Adheart\Logging\Inventory\LoggerCatalogProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

final class RegisterLoggerServicesPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(LoggerCatalogProvider::class)) {
            return;
        }

        $references = [];
        foreach ($this->collectCandidateServiceIds($container) as $serviceId) {
            if (!$container->has($serviceId)) {
                continue;
            }

            $references[$serviceId] = new Reference($serviceId);
        }

        $container
            ->getDefinition(LoggerCatalogProvider::class)
            ->setArgument('$loggerServices', $references);
    }

    /**
     * @return string[]
     */
    private function collectCandidateServiceIds(ContainerBuilder $container): array
    {
        $ids = ['logger', 'monolog.logger'];

        foreach (array_keys($container->getDefinitions()) as $serviceId) {
            if (str_starts_with($serviceId, 'monolog.logger.')) {
                $ids[] = $serviceId;
            }
        }

        foreach (array_keys($container->getAliases()) as $serviceId) {
            if (str_starts_with($serviceId, 'monolog.logger.')) {
                $ids[] = $serviceId;
            }
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
