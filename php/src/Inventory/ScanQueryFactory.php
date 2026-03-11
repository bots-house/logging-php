<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory;

use Adheart\Logging\Inventory\Model\ScanQuery;
use Symfony\Component\Console\Input\InputInterface;

final class ScanQueryFactory
{
    /**
     * @param mixed $value
     *
     * @return string[]
     */
    public function parseCsvList(mixed $value): array
    {
        $items = [];

        if (is_array($value)) {
            foreach ($value as $entry) {
                foreach (explode(',', (string) $entry) as $piece) {
                    $normalized = $this->normalizePathItem($piece);
                    if ($normalized !== null) {
                        $items[] = $normalized;
                    }
                }
            }
        } elseif (is_string($value)) {
            foreach (explode(',', $value) as $piece) {
                $normalized = $this->normalizePathItem($piece);
                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        }

        return array_values(array_unique($items));
    }

    public function build(InputInterface $input): ScanQuery
    {
        $severityMin = $this->normalizeValue($input->getOption('severity-min'));
        $sort = $this->normalizeValue($input->getOption('sort')) ?? 'message';
        $order = $this->normalizeValue($input->getOption('order')) ?? 'asc';
        $format = $this->normalizeValue($input->getOption('format')) ?? 'table';
        $view = $this->normalizeValue($input->getOption('view')) ?? 'compact';
        $contextMaxChars = max(0, (int) $input->getOption('context-max-chars'));
        $channels = array_map('strtolower', $this->parseCsvList($input->getOption('channel')));

        return new ScanQuery(
            paths: $this->parseCsvList($input->getOption('paths')),
            pathPrefixes: $this->parseCsvList($input->getOption('path-prefix')),
            excludePathPrefixes: $this->parseCsvList($input->getOption('exclude-path-prefix')),
            domainContexts: $this->parseCsvList($input->getOption('domain-context')),
            loggerNames: $this->parseCsvList($input->getOption('logger-name')),
            channels: $channels,
            contextKeys: array_map('strtolower', $this->parseCsvList($input->getOption('context-key'))),
            severityMin: $severityMin !== null ? strtolower($severityMin) : null,
            onlySeverities: array_map('strtolower', $this->parseCsvList($input->getOption('only-severity'))),
            strictSeverity: (bool) $input->getOption('strict-severity'),
            sort: $sort,
            order: $order,
            truncate: !(bool) $input->getOption('no-truncate'),
            view: $view,
            contextMaxChars: $contextMaxChars,
            onlyDynamicMessages: (bool) $input->getOption('only-dynamic-messages'),
            summary: (bool) $input->getOption('summary'),
            format: $format
        );
    }

    private function normalizePathItem(string $item): ?string
    {
        $trimmed = trim($item);
        if ($trimmed === '') {
            return null;
        }

        return trim(str_replace('\\', '/', $trimmed), '/');
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
