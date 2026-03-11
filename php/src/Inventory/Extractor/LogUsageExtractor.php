<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Extractor;

use Adheart\Logging\Inventory\Model\LogUsage;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;

final class LogUsageExtractor
{
    private const PSR3_METHODS = [
        'debug',
        'info',
        'notice',
        'warning',
        'error',
        'critical',
        'alert',
        'emergency',
        'log',
    ];

    /**
     * @param array<int, Node\Stmt> $stmts
     * @param string[] $knownLoggerNames
     *
     * @return LogUsage[]
     */
    public function extractFromAst(
        array $stmts,
        string $relativePath,
        array $knownLoggerNames,
        string $defaultChannel
    ): array {
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver(null, ['replaceNodes' => false]));

        /** @var array<int, Node\Stmt> $resolvedStmts */
        $resolvedStmts = $traverser->traverse($stmts);

        $collector = new UsageCollectingVisitor($relativePath, $knownLoggerNames, $defaultChannel);
        $collectorTraverser = new NodeTraverser();
        $collectorTraverser->addVisitor($collector);
        $collectorTraverser->traverse($resolvedStmts);

        return $collector->usages();
    }

    /**
     * @return string[]
     */
    public static function supportedMethods(): array
    {
        return self::PSR3_METHODS;
    }
}
