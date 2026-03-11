<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Model;

final class ScanResult
{
    /**
     * @param LogUsage[] $usages
     * @param array<string, string> $parseErrors
     */
    public function __construct(
        public readonly array $usages,
        public readonly array $parseErrors,
        public readonly int $scannedFiles
    ) {
    }
}
