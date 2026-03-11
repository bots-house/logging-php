<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Model;

final class LoggerCatalog
{
    /**
     * @param array<string, string> $serviceToLogger
     */
    public function __construct(
        public readonly array $serviceToLogger
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function loggerToService(): array
    {
        $result = [];
        foreach ($this->serviceToLogger as $serviceId => $loggerName) {
            if (!isset($result[$loggerName])) {
                $result[$loggerName] = $serviceId;
            }
        }

        ksort($result);

        return $result;
    }

    /**
     * @return string[]
     */
    public function knownLoggerNames(): array
    {
        return array_values(array_unique(array_values($this->serviceToLogger)));
    }

    public function loggerNameByServiceId(string $serviceId): ?string
    {
        return $this->serviceToLogger[$serviceId] ?? null;
    }
}
