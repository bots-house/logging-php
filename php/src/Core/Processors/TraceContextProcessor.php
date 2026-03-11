<?php

declare(strict_types=1);

namespace Adheart\Logging\Core\Processors;

use Adheart\Logging\Core\Trace\TraceContextProviderInterface;

final class TraceContextProcessor
{
    /** @var list<TraceContextProviderInterface> */
    private array $providers;

    /**
     * @param iterable<TraceContextProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        $this->providers = is_array($providers) ? array_values($providers) : iterator_to_array($providers, false);
    }

    public function __invoke(array $record): array
    {
        $trace = $record['extra']['trace'] ?? [];
        if (!is_array($trace)) {
            $trace = [];
        }

        foreach ($this->providers as $provider) {
            $provided = $provider->provide();

            foreach ($provided as $key => $value) {
                if (array_key_exists($key, $trace)) {
                    continue;
                }

                $trace[$key] = $value;
            }
        }

        $record['extra']['trace'] = $trace;

        return $record;
    }
}
