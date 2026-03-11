<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Renderer;

use Adheart\Logging\Inventory\Model\LogUsage;
use Adheart\Logging\Inventory\Model\ScanResult;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class LogUsageRenderer
{
    private const MESSAGE_TRUNCATE_LENGTH = 120;

    /**
     * @param LogUsage[] $usages
     */
    public function renderTable(
        SymfonyStyle $io,
        array $usages,
        bool $truncate,
        string $view,
        int $contextMaxChars
    ): void {
        if ($view === 'compact') {
            $this->renderCompact($io, $usages, $truncate, $contextMaxChars);
            return;
        }

        $table = new Table($io);
        $table->setHeaders([
            'message',
            'severity',
            'channel',
            'logger.name',
            'location',
            'domain',
            'message.type',
            'context.keys',
        ]);

        foreach ($usages as $usage) {
            $table->addRow([
                $this->renderMessage($usage, $truncate),
                $usage->severity,
                $usage->channel,
                $usage->loggerName,
                $usage->location(),
                $usage->domain,
                $usage->messageType,
                implode(',', $usage->contextKeys),
            ]);
        }

        $table->render();
    }

    /**
     * @param LogUsage[] $usages
     */
    public function renderJson(OutputInterface $output, array $usages, bool $summary, ScanResult $scanResult): void
    {
        $payload = [
            'usages' => array_map(
                static fn (LogUsage $usage): array => [
                    'message' => $usage->message,
                    'messageType' => $usage->messageType,
                    'severity' => $usage->severity,
                    'loggerName' => $usage->loggerName,
                    'channel' => $usage->channel,
                    'contextKeys' => $usage->contextKeys,
                    'context' => $usage->context,
                    'location' => $usage->location(),
                    'path' => $usage->path,
                    'line' => $usage->line,
                    'domain' => $usage->domain,
                ],
                $usages
            ),
            'parseErrors' => $scanResult->parseErrors,
            'scannedFiles' => $scanResult->scannedFiles,
        ];

        if ($summary) {
            $payload['summary'] = $this->summaryData($usages);
        }

        $output->writeln((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param LogUsage[] $usages
     */
    public function renderSummary(SymfonyStyle $io, array $usages, int $parseErrorsCount): void
    {
        $summary = $this->summaryData($usages);
        $io->section('Summary');
        $io->listing([
            sprintf('Total usages: %d', $summary['totalUsages']),
            sprintf('Unique messages: %d', $summary['uniqueMessages']),
            sprintf('Dynamic messages: %d', $summary['dynamicMessages']),
            sprintf('Parse errors: %d', $parseErrorsCount),
        ]);
    }

    private function renderMessage(LogUsage $usage, bool $truncate): string
    {
        if ($truncate === false || mb_strlen($usage->message) <= self::MESSAGE_TRUNCATE_LENGTH) {
            return $usage->message;
        }

        return mb_substr($usage->message, 0, self::MESSAGE_TRUNCATE_LENGTH) . '...';
    }

    /**
     * @param LogUsage[] $usages
     */
    private function renderCompact(SymfonyStyle $io, array $usages, bool $truncate, int $contextMaxChars): void
    {
        $position = 0;
        foreach ($usages as $usage) {
            if ($position > 0) {
                $io->writeln('<fg=gray>' . $this->separator() . '</>');
            }

            $message = OutputFormatter::escape($this->renderMessage($usage, $truncate));
            $location = OutputFormatter::escape($usage->location());
            $loggerName = OutputFormatter::escape($usage->loggerName);

            $io->writeln(sprintf(
                '<fg=gray>[%d]</> <options=bold>%s</>',
                $position + 1,
                $message
            ));
            $io->writeln(sprintf(
                '  %s  <fg=gray>%s</>  <fg=gray>%s</>',
                $this->severityBadge($usage->severity),
                $loggerName,
                $location
            ));

            $meta = sprintf(
                '  <fg=cyan>domain</>=<fg=cyan>%s</> <fg=gray>|</> <fg=magenta>channel</>=<fg=magenta>%s</>'
                . ' <fg=gray>|</> <fg=magenta>type</>=<fg=magenta>%s</> <fg=gray>|</> <fg=yellow>context.keys</>=%s',
                OutputFormatter::escape($usage->domain),
                OutputFormatter::escape($usage->channel),
                OutputFormatter::escape($usage->messageType),
                $this->renderContextKeys($usage->contextKeys)
            );
            $io->writeln($meta);

            if ($usage->context !== '') {
                $io->writeln(
                    '  <fg=yellow>context</>=' . $this->limitContext($usage->context, $contextMaxChars)
                );
            }

            ++$position;
        }
    }

    private function limitContext(string $context, int $maxChars): string
    {
        if ($maxChars === 0 || mb_strlen($context) <= $maxChars) {
            return OutputFormatter::escape($context);
        }

        $visible = mb_substr($context, 0, $maxChars);
        $left = mb_strlen($context) - $maxChars;

        return sprintf(
            '%s <fg=gray>... [truncated %d chars, adjust with --context-max-chars]</>',
            OutputFormatter::escape($visible),
            $left
        );
    }

    private function severityBadge(string $severity): string
    {
        $value = strtoupper($severity);

        return match ($severity) {
            'debug' => '<fg=gray>[' . $value . ']</>',
            'info' => '<fg=green>[' . $value . ']</>',
            'notice' => '<fg=cyan>[' . $value . ']</>',
            'warning' => '<fg=yellow;options=bold>[' . $value . ']</>',
            'error', 'critical', 'alert', 'emergency' => '<fg=red;options=bold>[' . $value . ']</>',
            default => '<fg=magenta;options=bold>[' . OutputFormatter::escape($value) . ']</>',
        };
    }

    private function separator(): string
    {
        return str_repeat('─', 100);
    }

    /**
     * @param string[] $contextKeys
     */
    private function renderContextKeys(array $contextKeys): string
    {
        if ($contextKeys === []) {
            return '<fg=gray>[0]</>';
        }

        $parts = [];
        foreach (array_values($contextKeys) as $index => $contextKey) {
            $parts[] = sprintf(
                '<fg=yellow;options=bold>[%d]</>=<fg=yellow>%s</>',
                $index + 1,
                OutputFormatter::escape($contextKey)
            );
        }

        return sprintf('<fg=yellow;options=bold>[%d]</> %s', count($contextKeys), implode(' <fg=gray>|</> ', $parts));
    }

    /**
     * @param LogUsage[] $usages
     *
     * @return array{totalUsages:int, uniqueMessages:int, dynamicMessages:int}
     */
    private function summaryData(array $usages): array
    {
        $messages = array_map(static fn (LogUsage $usage): string => $usage->message, $usages);
        $dynamic = array_filter($usages, static fn (LogUsage $usage): bool => $usage->messageType === 'dynamic');

        return [
            'totalUsages' => count($usages),
            'uniqueMessages' => count(array_unique($messages)),
            'dynamicMessages' => count($dynamic),
        ];
    }
}
