<?php

declare(strict_types=1);

namespace Adheart\Logging;

use Adheart\Logging\DependencyInjection\Compiler\RegisterLoggerServicesPass;
use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingFormatterPass;
use Adheart\Logging\DependencyInjection\Compiler\ApplyLoggingProcessorsPass;
use Adheart\Logging\DependencyInjection\AdheartLoggingExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class LoggingBundle extends Bundle
{
    #[\Override]
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new RegisterLoggerServicesPass());
        $container->addCompilerPass(new ApplyLoggingProcessorsPass());
        $container->addCompilerPass(new ApplyLoggingFormatterPass());
    }

    #[\Override]
    public function getContainerExtension(): ?ExtensionInterface
    {
        if (!class_exists(\Symfony\Component\DependencyInjection\Extension\Extension::class)) {
            return null;
        }

        if (!$this->extension instanceof ExtensionInterface) {
            $this->extension = new AdheartLoggingExtension();
        }

        return $this->extension;
    }
}
