<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Scanner;

use Adheart\Logging\Inventory\Extractor\LogUsageExtractor;
use Adheart\Logging\Inventory\Model\ScanQuery;
use Adheart\Logging\Inventory\Model\ScanResult;
use PhpParser\Error;
use PhpParser\ParserFactory;

final class LogUsageScanner
{
    private const HARD_EXCLUDED_PREFIXES = [
        'vendor/',
        'var/cache/',
        'node_modules/',
        'var/log/',
    ];

    private readonly string $projectDir;

    public function __construct(
        string $projectDir,
        private readonly LogUsageExtractor $extractor
    ) {
        $this->projectDir = rtrim(str_replace('\\', '/', $projectDir), '/');
    }

    /**
     * @param string[] $knownLoggerNames
     * @param callable():void|null $onProgress
     */
    public function scan(
        ScanQuery $query,
        array $knownLoggerNames,
        string $defaultChannel,
        ?callable $onProgress = null
    ): ScanResult {
        $files = $this->discoverFiles($query);
        $usages = [];
        $parseErrors = [];

        $parser = (new ParserFactory())->createForNewestSupportedVersion();

        $totalFiles = count($files);
        foreach ($files as $relativePath) {
            if ($onProgress !== null) {
                $onProgress();
            }

            $absolutePath = $this->projectDir . '/' . $relativePath;
            $code = @file_get_contents($absolutePath);
            if ($code === false) {
                continue;
            }

            try {
                $stmts = $parser->parse($code);
            } catch (Error $error) {
                $parseErrors[$relativePath] = $error->getMessage();
                continue;
            }

            if ($stmts === null) {
                continue;
            }

            $usages = array_merge(
                $usages,
                $this->extractor->extractFromAst(
                    $stmts,
                    $relativePath,
                    $knownLoggerNames,
                    $defaultChannel
                )
            );
        }

        return new ScanResult($usages, $parseErrors, $totalFiles);
    }

    /**
     * @return string[]
     */
    private function discoverFiles(ScanQuery $query): array
    {
        $roots = [];
        foreach ($query->paths as $path) {
            $normalized = $this->normalizeInputPath($path);
            if ($normalized !== null && is_dir($this->projectDir . '/' . $normalized)) {
                $roots[] = $normalized;
            }
        }

        $roots = array_values(array_unique($roots));

        $files = [];
        foreach ($roots as $root) {
            $directory = new \RecursiveDirectoryIterator(
                $this->projectDir . '/' . $root,
                \FilesystemIterator::SKIP_DOTS
            );
            $iterator = new \RecursiveIteratorIterator($directory);

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo instanceof \SplFileInfo || !$fileInfo->isFile()) {
                    continue;
                }

                if (strtolower($fileInfo->getExtension()) !== 'php') {
                    continue;
                }

                $fullPath = str_replace('\\', '/', $fileInfo->getPathname());
                $relativePath = ltrim(substr($fullPath, strlen($this->projectDir)), '/');
                if ($this->isIncludedByFilters($relativePath, $query) === false) {
                    continue;
                }

                $files[] = $relativePath;
            }
        }

        sort($files);

        return $files;
    }

    private function isIncludedByFilters(string $relativePath, ScanQuery $query): bool
    {
        foreach (self::HARD_EXCLUDED_PREFIXES as $excludedPrefix) {
            if (str_starts_with($relativePath, $excludedPrefix)) {
                return false;
            }
        }

        foreach ($query->excludePathPrefixes as $excludedPrefix) {
            if (str_starts_with($relativePath, $excludedPrefix)) {
                return false;
            }
        }

        if ($query->pathPrefixes === []) {
            return true;
        }

        foreach ($query->pathPrefixes as $includedPrefix) {
            if (str_starts_with($relativePath, $includedPrefix)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeInputPath(string $path): ?string
    {
        $normalized = trim(str_replace('\\', '/', $path));
        if ($normalized === '') {
            return null;
        }

        if (str_starts_with($normalized, $this->projectDir . '/')) {
            $normalized = substr($normalized, strlen($this->projectDir) + 1);
        }

        return trim($normalized, '/');
    }
}
