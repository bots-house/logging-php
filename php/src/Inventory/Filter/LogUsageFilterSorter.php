<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Filter;

use Adheart\Logging\Inventory\Model\LogUsage;
use Adheart\Logging\Inventory\Model\ScanQuery;

final class LogUsageFilterSorter
{
    private const SEVERITY_RANK = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /**
     * @param LogUsage[] $usages
     *
     * @return LogUsage[]
     */
    public function apply(ScanQuery $query, array $usages): array
    {
        $filtered = array_values(array_filter($usages, fn (LogUsage $usage): bool => $this->passes($query, $usage)));

        $wrapped = [];
        foreach ($filtered as $index => $usage) {
            $wrapped[] = ['index' => $index, 'usage' => $usage];
        }

        $sortFields = $this->sortFields($query->sort);
        usort($wrapped, function (array $left, array $right) use ($sortFields, $query): int {
            $leftUsage = $left['usage'];
            $rightUsage = $right['usage'];

            foreach ($sortFields as $field) {
                $cmp = $this->compareByField($field, $leftUsage, $rightUsage);
                if ($cmp !== 0) {
                    return $query->order === 'desc' ? -$cmp : $cmp;
                }
            }

            return $left['index'] <=> $right['index'];
        });

        return array_map(static fn (array $item): LogUsage => $item['usage'], $wrapped);
    }

    private function passes(ScanQuery $query, LogUsage $usage): bool
    {
        if ($query->onlyDynamicMessages && $usage->messageType !== 'dynamic') {
            return false;
        }

        if ($query->domainContexts !== [] && !in_array($usage->domain, $query->domainContexts, true)) {
            return false;
        }

        if ($query->loggerNames !== [] && !in_array($usage->loggerName, $query->loggerNames, true)) {
            return false;
        }

        if ($query->channels !== [] && !in_array(strtolower($usage->channel), $query->channels, true)) {
            return false;
        }

        if (!$this->passesContextKeyFilter($query, $usage)) {
            return false;
        }

        if (!$this->passesOnlySeverity($query, $usage)) {
            return false;
        }

        return $this->passesSeverityMin($query, $usage);
    }

    private function passesOnlySeverity(ScanQuery $query, LogUsage $usage): bool
    {
        if ($query->onlySeverities === []) {
            return true;
        }

        if ($usage->severity === 'dynamic') {
            return !$query->strictSeverity && in_array('dynamic', $query->onlySeverities, true);
        }

        return in_array($usage->severity, $query->onlySeverities, true);
    }

    private function passesSeverityMin(ScanQuery $query, LogUsage $usage): bool
    {
        if ($query->severityMin === null) {
            return true;
        }

        if ($usage->severity === 'dynamic') {
            return !$query->strictSeverity;
        }

        $minRank = self::SEVERITY_RANK[$query->severityMin] ?? null;
        $usageRank = self::SEVERITY_RANK[$usage->severity] ?? null;

        if ($minRank === null || $usageRank === null) {
            return false;
        }

        return $usageRank >= $minRank;
    }

    private function passesContextKeyFilter(ScanQuery $query, LogUsage $usage): bool
    {
        if ($query->contextKeys === []) {
            return true;
        }

        $usageKeys = array_map('strtolower', $usage->contextKeys);
        foreach ($query->contextKeys as $contextKey) {
            if (in_array($contextKey, $usageKeys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function sortFields(string $sort): array
    {
        return match ($sort) {
            'message' => ['message', 'severity', 'logger', 'path'],
            'severity' => ['severity', 'message', 'logger', 'path'],
            'logger' => ['logger', 'message', 'severity', 'path'],
            'path' => ['path', 'message', 'severity', 'logger'],
            default => ['message', 'severity', 'logger', 'path'],
        };
    }

    private function compareByField(string $field, LogUsage $left, LogUsage $right): int
    {
        return match ($field) {
            'message' => strcmp($left->message, $right->message),
            'severity' => $this->severityRank($left->severity) <=> $this->severityRank($right->severity),
            'logger' => strcmp($left->loggerName, $right->loggerName),
            'path' => $this->comparePath($left, $right),
            default => 0,
        };
    }

    private function severityRank(string $severity): int
    {
        if ($severity === 'dynamic') {
            return -1;
        }

        return self::SEVERITY_RANK[$severity] ?? -1;
    }

    private function comparePath(LogUsage $left, LogUsage $right): int
    {
        $pathCmp = strcmp($left->path, $right->path);
        if ($pathCmp !== 0) {
            return $pathCmp;
        }

        return $left->line <=> $right->line;
    }
}
