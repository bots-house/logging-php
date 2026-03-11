<?php

declare(strict_types=1);

namespace Adheart\Logging\DependencyInjection\Compiler;

use InvalidArgumentException;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ApplyLoggingFormatterPass implements CompilerPassInterface
{
    #[\Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('logging.formatter_service_id')) {
            return;
        }

        /** @var string|null $formatterServiceId */
        $formatterServiceId = $container->getParameter('logging.formatter_service_id');
        if ($formatterServiceId === null || $formatterServiceId === '') {
            return;
        }

        if (!$container->hasDefinition($formatterServiceId) && !$container->hasAlias($formatterServiceId)) {
            throw new InvalidArgumentException(sprintf(
                'Configured logging formatter "%s" is not a registered service.',
                $formatterServiceId
            ));
        }

        $handlerIds = $this->collectHandlerDefinitionIds($container);

        foreach ($handlerIds as $handlerId) {
            $definition = $container->getDefinition($handlerId);

            if (!$this->supportsFormatter($container, $definition)) {
                continue;
            }

            $definition->addMethodCall('setFormatter', [new Reference($formatterServiceId)]);
        }
    }

    /**
     * @return array<int,string>
     */
    private function collectHandlerDefinitionIds(ContainerBuilder $container): array
    {
        $ids = [];

        foreach (array_keys($container->getDefinitions()) as $id) {
            if ($id === 'monolog.handler' || str_starts_with($id, 'monolog.handler.')) {
                $ids[] = $id;
            }
        }

        foreach ($container->getAliases() as $id => $alias) {
            if ($id !== 'monolog.handler' && !str_starts_with($id, 'monolog.handler.')) {
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

    private function supportsFormatter(ContainerBuilder $container, Definition $definition): bool
    {
        $class = $this->resolveDefinitionClass($container, $definition);
        if ($class === null || !class_exists($class)) {
            return true;
        }

        return method_exists($class, 'setFormatter');
    }

    private function resolveDefinitionClass(ContainerBuilder $container, Definition $definition): ?string
    {
        if ($definition->getClass() !== null) {
            return $container->getParameterBag()->resolveValue($definition->getClass());
        }

        if ($definition instanceof ChildDefinition) {
            $parentId = $definition->getParent();
            if ($container->hasDefinition($parentId)) {
                return $this->resolveDefinitionClass($container, $container->getDefinition($parentId));
            }
        }

        return null;
    }
}
