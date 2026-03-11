<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Model;

final class ScanQuery
{
    /**
     * @param string[] $paths
     * @param string[] $pathPrefixes
     * @param string[] $excludePathPrefixes
     * @param string[] $domainContexts
     * @param string[] $loggerNames
     * @param string[] $channels
     * @param string[] $contextKeys
     * @param string[] $onlySeverities
     */
    public function __construct(
        public readonly array $paths,
        public readonly array $pathPrefixes,
        public readonly array $excludePathPrefixes,
        public readonly array $domainContexts,
        public readonly array $loggerNames,
        public readonly array $channels,
        public readonly array $contextKeys,
        public readonly ?string $severityMin,
        public readonly array $onlySeverities,
        public readonly bool $strictSeverity,
        public readonly string $sort,
        public readonly string $order,
        public readonly bool $truncate,
        public readonly string $view,
        public readonly int $contextMaxChars,
        public readonly bool $onlyDynamicMessages,
        public readonly bool $summary,
        public readonly string $format
    ) {
    }
}
