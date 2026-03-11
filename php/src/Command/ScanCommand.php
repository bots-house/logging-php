<?php

declare(strict_types=1);

namespace Adheart\Logging\Command;

use Adheart\Logging\Inventory\Filter\LogUsageFilterSorter;
use Adheart\Logging\Inventory\LoggerCatalogProvider;
use Adheart\Logging\Inventory\Renderer\LogUsageRenderer;
use Adheart\Logging\Inventory\ScanQueryFactory;
use Adheart\Logging\Inventory\Scanner\LogUsageScanner;
use Adheart\Logging\Inventory\Model\LoggerCatalog;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ScanCommand extends Command
{
    public function __construct(
        private readonly LoggerCatalogProvider $loggerCatalogProvider,
        private readonly ScanQueryFactory $scanQueryFactory,
        private readonly LogUsageScanner $scanner,
        private readonly LogUsageFilterSorter $filterSorter,
        private readonly LogUsageRenderer $renderer
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('logging:scan');
        $this->setDescription('Scan code for PSR-3 logger usages and print inventory.');
        $this
            ->addOption(
                'paths',
                null,
                InputOption::VALUE_REQUIRED,
                'CSV with scan roots.',
                'src'
            )
            ->addOption(
                'path-prefix',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Include only relative path prefixes (CSV or repeated, e.g. src/Billing,src/User).'
            )
            ->addOption(
                'exclude-path-prefix',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Exclude relative path prefixes (CSV or repeated, e.g. src/Legacy,src/Ads/Parsing).'
            )
            ->addOption(
                'domain-context',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by domain context name from src/<Domain>/... '
                . '(CSV or repeated, e.g. Billing,User,Unknown).'
            )
            ->addOption(
                'logger-name',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by logger.name (CSV or repeated). '
                . 'Use --list-loggers to inspect available values; supports unknown.'
            )
            ->addOption(
                'channel',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by logging channel (CSV or repeated).'
            )
            ->addOption(
                'context-key',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Filter by context key (CSV or repeated, e.g. exception,payment_id).'
            )
            ->addOption(
                'severity-min',
                null,
                InputOption::VALUE_REQUIRED,
                'Minimum severity: debug|info|notice|warning|error|critical|alert|emergency.'
            )
            ->addOption(
                'only-severity',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Only severity set (CSV or repeated): '
                . 'debug|info|notice|warning|error|critical|alert|emergency|dynamic.'
            )
            ->addOption(
                'strict-severity',
                null,
                InputOption::VALUE_NONE,
                'Exclude dynamic severity rows from severity filters.'
            )
            ->addOption(
                'sort',
                null,
                InputOption::VALUE_REQUIRED,
                'Sort key: message|severity|logger|path.',
                'message'
            )
            ->addOption(
                'order',
                null,
                InputOption::VALUE_REQUIRED,
                'Sort order: asc|desc.',
                'asc'
            )
            ->addOption(
                'list-loggers',
                null,
                InputOption::VALUE_NONE,
                'Print known logger list and exit.'
            )
            ->addOption(
                'no-truncate',
                null,
                InputOption::VALUE_NONE,
                'Do not truncate long messages in table output.'
            )
            ->addOption(
                'view',
                null,
                InputOption::VALUE_REQUIRED,
                'Table view mode: compact|table.',
                'compact'
            )
            ->addOption(
                'context-max-chars',
                null,
                InputOption::VALUE_REQUIRED,
                'Max context length in output (0 = unlimited).',
                '4000'
            )
            ->addOption(
                'only-dynamic-messages',
                null,
                InputOption::VALUE_NONE,
                'Show only usages with dynamic message templates.'
            )
            ->addOption(
                'summary',
                null,
                InputOption::VALUE_NONE,
                'Print summary counters.'
            )
            ->addOption(
                'format',
                null,
                InputOption::VALUE_REQUIRED,
                'Output format: table|json.',
                'table'
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $query = $this->scanQueryFactory->build($input);
        if (!$this->isValidInput($query->sort, $query->order, $query->format, $query->view, $io)) {
            return Command::FAILURE;
        }

        $catalog = $this->loggerCatalogProvider->buildCatalog();
        $defaultChannel = $this->resolveDefaultChannel($catalog);

        if ((bool) $input->getOption('list-loggers')) {
            $this->renderLoggerList($io, $catalog->loggerToService());
            return Command::SUCCESS;
        }

        $io->text('Scanning files...');
        $io->progressStart();
        $scanResult = $this->scanner->scan(
            $query,
            $catalog->knownLoggerNames(),
            $defaultChannel,
            static function () use ($io): void {
                $io->progressAdvance();
            }
        );
        $io->progressFinish();

        $usages = $this->filterSorter->apply($query, $scanResult->usages);

        if ($query->format === 'json') {
            $this->renderer->renderJson($output, $usages, $query->summary, $scanResult);
        } else {
            $this->renderer->renderTable($io, $usages, $query->truncate, $query->view, $query->contextMaxChars);
            if ($query->summary) {
                $this->renderer->renderSummary($io, $usages, count($scanResult->parseErrors));
            }
        }

        if ($query->format !== 'json' && $scanResult->parseErrors !== []) {
            $io->warning(sprintf('Skipped %d file(s) with parse errors.', count($scanResult->parseErrors)));
            foreach ($scanResult->parseErrors as $file => $error) {
                $io->text(sprintf('%s: %s', $file, $error));
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $loggerToService
     */
    private function renderLoggerList(SymfonyStyle $io, array $loggerToService): void
    {
        if ($loggerToService === []) {
            $io->warning('No logger services found in the container.');
            return;
        }

        $rows = [];
        foreach ($loggerToService as $loggerName => $serviceId) {
            $rows[] = [$loggerName, $serviceId];
        }

        $io->table(['logger.name', 'service_id'], $rows);
    }

    private function resolveDefaultChannel(LoggerCatalog $catalog): string
    {
        foreach (['logger', 'monolog.logger'] as $serviceId) {
            $channel = $catalog->loggerNameByServiceId($serviceId);
            if ($channel !== null) {
                return $channel;
            }
        }

        $known = $catalog->knownLoggerNames();
        if ($known !== []) {
            return $known[0];
        }

        return 'app';
    }

    private function isValidInput(string $sort, string $order, string $format, string $view, SymfonyStyle $io): bool
    {
        if (!in_array($sort, ['message', 'severity', 'logger', 'path'], true)) {
            $io->error('Invalid --sort value. Expected message|severity|logger|path.');
            return false;
        }

        if (!in_array($order, ['asc', 'desc'], true)) {
            $io->error('Invalid --order value. Expected asc|desc.');
            return false;
        }

        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('Invalid --format value. Expected table|json.');
            return false;
        }

        if (!in_array($view, ['compact', 'table'], true)) {
            $io->error('Invalid --view value. Expected compact|table.');
            return false;
        }

        return true;
    }
}
