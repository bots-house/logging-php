<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory;

use Adheart\Logging\Inventory\Model\LoggerCatalog;
use Monolog\Logger;

final class LoggerCatalogProvider
{
    /**
     * @param array<string, mixed> $loggerServices
     */
    public function __construct(
        private readonly array $loggerServices = []
    ) {
    }

    public function buildCatalog(): LoggerCatalog
    {
        $serviceToLogger = [];

        foreach ($this->loggerServices as $serviceId => $service) {
            if (!is_object($service)) {
                continue;
            }

            $loggerName = $serviceId;

            if ($service instanceof Logger) {
                $loggerName = $service->getName();
            } elseif ($serviceId === 'logger' || $serviceId === 'monolog.logger') {
                $loggerName = 'app';
            }

            $serviceToLogger[$serviceId] = $loggerName;
        }

        if (!isset($serviceToLogger['logger'])) {
            $serviceToLogger['logger'] = 'app';
        }

        ksort($serviceToLogger);

        return new LoggerCatalog($serviceToLogger);
    }
}
