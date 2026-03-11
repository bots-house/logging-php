<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Extractor;

use Adheart\Logging\Inventory\Model\LogUsage;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard;

final class UsageCollectingVisitor extends NodeVisitorAbstract
{
    /** @var LogUsage[] */
    private array $usages = [];

    /** @var array<int, array{loggerVars: array<string, string>, className: string|null, propertyLoggers: array<string, string>, loggerProperties: array<string, bool>}> */
    private array $scopeStack = [];

    /** @var array<string, string> */
    private array $knownLoggerNames = [];
    private Standard $prettyPrinter;
    private readonly string $defaultChannel;

    public function __construct(
        private readonly string $relativePath,
        array $knownLoggerNames,
        string $defaultChannel
    ) {
        $this->prettyPrinter = new Standard();
        $this->scopeStack[] = [
            'loggerVars' => [],
            'className' => null,
            'propertyLoggers' => [],
            'loggerProperties' => [],
        ];

        $this->defaultChannel = $defaultChannel;

        foreach ($knownLoggerNames as $knownLoggerName) {
            $this->knownLoggerNames[strtolower($knownLoggerName)] = $knownLoggerName;
        }
    }

    /**
     * @return LogUsage[]
     */
    public function usages(): array
    {
        return $this->usages;
    }

    #[\Override]
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof ClassLike) {
            $this->scopeStack[] = [
                'loggerVars' => [],
                'className' => $this->className($node),
                'propertyLoggers' => [],
                'loggerProperties' => [],
            ];

            return null;
        }

        if ($node instanceof ClassMethod) {
            $scope = $this->currentScope();
            if ($scope === null) {
                return null;
            }

            $scope['loggerVars'] = [];
            $scope = $this->captureParams($node, $scope);
            $this->replaceCurrentScope($scope);

            return null;
        }

        if ($node instanceof Property) {
            $scope = $this->currentScope();
            if ($scope === null) {
                return null;
            }

            $scope = $this->captureProperty($node, $scope);
            $this->replaceCurrentScope($scope);

            return null;
        }

        if ($node instanceof Assign) {
            $scope = $this->currentScope();
            if ($scope === null) {
                return null;
            }

            $resolvedLogger = $this->resolveLoggerExpression($node->expr, $scope);
            if ($resolvedLogger === null) {
                return null;
            }

            if ($node->var instanceof Variable && is_string($node->var->name)) {
                $scope['loggerVars'][$node->var->name] = $resolvedLogger;
                $this->replaceCurrentScope($scope);
                return null;
            }

            if (
                $node->var instanceof PropertyFetch
                && $node->var->var instanceof Variable
                && $node->var->var->name === 'this'
                && $node->var->name instanceof Identifier
            ) {
                $property = $node->var->name->toString();
                $scope['propertyLoggers'][$property] = $resolvedLogger;
                $scope['loggerProperties'][$property] = true;
                $this->replaceCurrentScope($scope);
            }

            return null;
        }

        if ($node instanceof MethodCall) {
            $usage = $this->extractFromMethodCall($node);
            if ($usage !== null) {
                $this->usages[] = $usage;
            }

            return null;
        }

        if ($node instanceof StaticCall) {
            $usage = $this->extractFromStaticCall($node);
            if ($usage !== null) {
                $this->usages[] = $usage;
            }

            return null;
        }

        return null;
    }

    #[\Override]
    public function leaveNode(Node $node)
    {
        if ($node instanceof ClassMethod) {
            $scope = $this->currentScope();
            if ($scope !== null) {
                $scope['loggerVars'] = [];
                $this->replaceCurrentScope($scope);
            }
        }

        if ($node instanceof ClassLike) {
            array_pop($this->scopeStack);
        }

        return null;
    }

    private function extractFromMethodCall(MethodCall $call): ?LogUsage
    {
        $method = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
        if ($method === null || !in_array($method, LogUsageExtractor::supportedMethods(), true)) {
            return null;
        }

        $scope = $this->currentScope();
        if ($scope === null) {
            return null;
        }

        $loggerName = $this->resolveLoggerNameForMethodCall($call, $scope);
        if ($loggerName === null) {
            return null;
        }

        [$severity, $messageArg, $contextArg] = $this->extractSeverityAndMessageArg($method, $call->args, $scope);
        [$message, $messageType] = $this->extractMessage($messageArg, $scope);
        [$contextKeys, $context] = $this->extractContext($contextArg);
        $domain = $this->domainContext($this->relativePath);

        return new LogUsage(
            message: $message,
            messageType: $messageType,
            severity: $severity,
            loggerName: $loggerName,
            channel: $this->resolveChannel($loggerName, $domain),
            contextKeys: $contextKeys,
            context: $context,
            path: $this->relativePath,
            line: $call->getStartLine(),
            domain: $domain
        );
    }

    private function extractFromStaticCall(StaticCall $call): ?LogUsage
    {
        $method = $call->name instanceof Identifier ? strtolower($call->name->toString()) : null;
        if ($method === null || !in_array($method, LogUsageExtractor::supportedMethods(), true)) {
            return null;
        }

        $className = $this->classNameFromExpr($call->class);
        $classLooksLikeLogger = $className !== null
            && (str_ends_with(strtolower($className), 'logger') || strtolower($className) === 'log');
        if (!$classLooksLikeLogger) {
            return null;
        }

        $scope = $this->currentScope();
        if ($scope === null) {
            return null;
        }

        [$severity, $messageArg, $contextArg] = $this->extractSeverityAndMessageArg($method, $call->args, $scope);
        [$message, $messageType] = $this->extractMessage($messageArg, $scope);
        [$contextKeys, $context] = $this->extractContext($contextArg);
        $domain = $this->domainContext($this->relativePath);

        return new LogUsage(
            message: $message,
            messageType: $messageType,
            severity: $severity,
            loggerName: 'unknown',
            channel: $this->resolveChannel('unknown', $domain),
            contextKeys: $contextKeys,
            context: $context,
            path: $this->relativePath,
            line: $call->getStartLine(),
            domain: $domain
        );
    }

    /**
     * @param array<int, Arg|VariadicPlaceholder> $args
     * @param array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * } $scope
     *
     * @return array{0: string, 1: Expr|null, 2: Expr|null}
     */
    private function extractSeverityAndMessageArg(string $method, array $args, array $scope): array
    {
        if ($method !== 'log') {
            return [$method, $this->argumentValue($args, 0), $this->argumentValue($args, 1)];
        }

        $levelExpr = $this->argumentValue($args, 0);
        $severity = 'dynamic';
        if ($levelExpr instanceof String_) {
            $severity = strtolower($levelExpr->value);
        } elseif ($levelExpr instanceof ClassConstFetch) {
            $severity = $this->classConstFetchToString($levelExpr, $scope);
        }

        return [$severity, $this->argumentValue($args, 1), $this->argumentValue($args, 2)];
    }

    /**
     * @param array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * } $scope
     *
     * @return array{0: string, 1: string}
     */
    private function extractMessage(?Expr $messageExpr, array $scope): array
    {
        if ($messageExpr === null) {
            return ['dynamic', 'dynamic'];
        }

        if ($messageExpr instanceof String_) {
            return [$messageExpr->value, 'literal'];
        }

        if ($messageExpr instanceof ClassConstFetch) {
            return [$this->classConstFetchToString($messageExpr, $scope), 'const'];
        }

        if ($messageExpr instanceof FuncCall) {
            if ($this->functionName($messageExpr->name) === 'sprintf') {
                $firstArg = $this->argumentValue($messageExpr->args, 0);
                if ($firstArg instanceof String_) {
                    return [$firstArg->value, 'sprintf'];
                }
            }
        }

        if ($messageExpr instanceof \PhpParser\Node\Expr\BinaryOp\Concat) {
            $concat = $this->concatLiteral($messageExpr);
            if ($concat !== null) {
                return [$concat, 'concat'];
            }
        }

        return [$this->expressionToString($messageExpr), 'dynamic'];
    }

    /**
     * @return array{0: string[], 1: string}
     */
    private function extractContext(?Expr $contextExpr): array
    {
        if ($contextExpr === null) {
            return [[], ''];
        }

        if ($contextExpr instanceof Array_) {
            return [$this->arrayContextKeys($contextExpr), $this->expressionToString($contextExpr)];
        }

        return [[], $this->expressionToString($contextExpr)];
    }

    /**
     * @return string[]
     */
    private function arrayContextKeys(Array_ $array): array
    {
        $keys = [];
        foreach ($array->items as $item) {
            if ($item === null || $item->key === null) {
                continue;
            }

            if ($item->key instanceof String_) {
                $keys[] = $item->key->value;
                continue;
            }

            if ($item->key instanceof LNumber) {
                $keys[] = (string) $item->key->value;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * } $scope
     */
    private function resolveLoggerExpression(Expr $expr, array $scope): ?string
    {
        if ($expr instanceof MethodCall && $this->isContainerGetCall($expr)) {
            $serviceArg = $expr->args[0]->value ?? null;
            if ($serviceArg instanceof String_) {
                return $this->loggerNameFromServiceId($serviceArg->value) ?? 'unknown';
            }
        }

        if ($expr instanceof Variable && is_string($expr->name)) {
            return $scope['loggerVars'][$expr->name] ?? null;
        }

        if (
            $expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier
        ) {
            $name = $expr->name->toString();
            if (isset($scope['propertyLoggers'][$name])) {
                return $scope['propertyLoggers'][$name];
            }
        }

        return null;
    }

    /**
     * @param array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * } $scope
     */
    private function resolveLoggerNameForMethodCall(MethodCall $call, array $scope): ?string
    {
        if ($this->isContainerGetCall($call)) {
            $serviceArg = $call->args[0]->value ?? null;
            if ($serviceArg instanceof String_) {
                return $this->loggerNameFromServiceId($serviceArg->value) ?? 'unknown';
            }
        }

        if ($call->var instanceof MethodCall && $this->isContainerGetCall($call->var)) {
            $serviceArg = $call->var->args[0]->value ?? null;
            if ($serviceArg instanceof String_) {
                return $this->loggerNameFromServiceId($serviceArg->value) ?? 'unknown';
            }
        }

        if ($call->var instanceof Variable && is_string($call->var->name)) {
            $varName = $call->var->name;
            if (isset($scope['loggerVars'][$varName])) {
                return $scope['loggerVars'][$varName];
            }

            if ($this->isProbableLoggerVariableName($varName)) {
                return $this->guessLoggerFromVariableName($varName) ?? 'unknown';
            }

            return null;
        }

        if (
            $call->var instanceof PropertyFetch
            && $call->var->var instanceof Variable
            && $call->var->var->name === 'this'
            && $call->var->name instanceof Identifier
        ) {
            $propertyName = $call->var->name->toString();
            if (isset($scope['propertyLoggers'][$propertyName])) {
                return $scope['propertyLoggers'][$propertyName];
            }

            if (
                isset($scope['loggerProperties'][$propertyName])
                || $this->isProbableLoggerVariableName($propertyName)
            ) {
                return $this->guessLoggerFromVariableName($propertyName) ?? 'unknown';
            }

            return null;
        }

        return null;
    }

    /**
     * @param array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * } $scope
     *
     * @return array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * }
     */
    private function captureParams(ClassMethod $method, array $scope): array
    {
        foreach ($method->params as $param) {
            if (!$this->isLoggerType($param->type)) {
                continue;
            }

            if (!$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $varName = $param->var->name;
            $serviceLoggerName = $this->extractLoggerNameFromAutowireAttribute($param->attrGroups);
            $scope['loggerVars'][$varName] = $serviceLoggerName
                ?? $this->guessLoggerFromVariableName($varName)
                ?? 'unknown';

            if ($param->flags !== 0) {
                $scope['loggerProperties'][$varName] = true;
                $scope['propertyLoggers'][$varName] = $scope['loggerVars'][$varName];
            }
        }

        return $scope;
    }

    /**
     * @param array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * } $scope
     *
     * @return array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * }
     */
    private function captureProperty(Property $property, array $scope): array
    {
        if (!$this->isLoggerType($property->type)) {
            return $scope;
        }

        $serviceLoggerName = $this->extractLoggerNameFromAutowireAttribute($property->attrGroups);
        foreach ($property->props as $propertyProperty) {
            $name = $propertyProperty->name->toString();
            $scope['loggerProperties'][$name] = true;
            $scope['propertyLoggers'][$name] = $serviceLoggerName
                ?? $this->guessLoggerFromVariableName($name)
                ?? 'unknown';
        }

        return $scope;
    }

    /**
     * @param array<int, Node\AttributeGroup> $attributeGroups
     */
    private function extractLoggerNameFromAutowireAttribute(array $attributeGroups): ?string
    {
        foreach ($attributeGroups as $attributeGroup) {
            foreach ($attributeGroup->attrs as $attribute) {
                $name = $this->attributeName($attribute);
                if ($name !== 'Symfony\\Component\\DependencyInjection\\Attribute\\Autowire' && $name !== 'Autowire') {
                    continue;
                }

                foreach ($attribute->args as $arg) {
                    if (!$arg->name instanceof Identifier || $arg->name->toString() !== 'service') {
                        continue;
                    }

                    if ($arg->value instanceof String_) {
                        return $this->loggerNameFromServiceId($arg->value->value) ?? 'unknown';
                    }
                }
            }
        }

        return null;
    }

    private function loggerNameFromServiceId(string $serviceId): ?string
    {
        if ($serviceId === 'logger' || $serviceId === 'monolog.logger') {
            return 'app';
        }

        if (str_starts_with($serviceId, 'monolog.logger.')) {
            return substr($serviceId, strlen('monolog.logger.'));
        }

        return null;
    }

    private function isContainerGetCall(MethodCall $call): bool
    {
        if (!$call->name instanceof Identifier || strtolower($call->name->toString()) !== 'get') {
            return false;
        }

        if (!(($call->args[0]->value ?? null) instanceof String_)) {
            return false;
        }

        $var = $call->var;
        if ($var instanceof Variable && is_string($var->name)) {
            return in_array(strtolower($var->name), ['container', 'containerinterface'], true);
        }

        if (
            $var instanceof PropertyFetch
            && $var->var instanceof Variable
            && $var->var->name === 'this'
            && $var->name instanceof Identifier
        ) {
            return in_array(strtolower($var->name->toString()), ['container', 'containerinterface'], true);
        }

        return false;
    }

    private function isLoggerType(?Node $type): bool
    {
        if ($type === null) {
            return false;
        }

        if ($type instanceof NullableType) {
            return $this->isLoggerType($type->type);
        }

        if (!$type instanceof Name) {
            return false;
        }

        $typeName = $this->fullName($type);

        return in_array($typeName, ['Psr\\Log\\LoggerInterface', 'Monolog\\Logger'], true);
    }

    private function isProbableLoggerVariableName(string $name): bool
    {
        return str_ends_with(strtolower($name), 'logger') || strtolower($name) === 'log';
    }

    private function guessLoggerFromVariableName(string $name): ?string
    {
        $normalized = strtolower($name);
        $normalized = preg_replace('/logger$/', '', $normalized) ?? $normalized;
        $normalized = trim($normalized, '_');

        if ($normalized === '' || $normalized === 'this') {
            return null;
        }

        if (isset($this->knownLoggerNames[$normalized])) {
            return $this->knownLoggerNames[$normalized];
        }

        return null;
    }

    private function resolveChannel(string $loggerName, string $domain): string
    {
        if ($loggerName !== 'unknown') {
            return $loggerName;
        }

        $domainKey = strtolower($domain);
        if (isset($this->knownLoggerNames[$domainKey])) {
            return $this->knownLoggerNames[$domainKey];
        }

        return $this->defaultChannel;
    }

    private function concatLiteral(\PhpParser\Node\Expr\BinaryOp\Concat $concat): ?string
    {
        $left = $concat->left;
        $right = $concat->right;

        $leftValue = null;
        if ($left instanceof String_) {
            $leftValue = $left->value;
        } elseif ($left instanceof \PhpParser\Node\Expr\BinaryOp\Concat) {
            $leftValue = $this->concatLiteral($left);
        }

        if ($leftValue === null) {
            return null;
        }

        $rightValue = null;
        if ($right instanceof String_) {
            $rightValue = $right->value;
        } elseif ($right instanceof \PhpParser\Node\Expr\BinaryOp\Concat) {
            $rightValue = $this->concatLiteral($right);
        }

        if ($rightValue === null) {
            return null;
        }

        return $leftValue . $rightValue;
    }

    private function classConstFetchToString(ClassConstFetch $classConstFetch, array $scope): string
    {
        $className = $this->classNameFromExpr($classConstFetch->class);
        if ($className === 'self' && ($scope['className'] ?? null) !== null) {
            $className = $scope['className'];
        }

        $constName = $classConstFetch->name instanceof Identifier ? $classConstFetch->name->toString() : 'CONST';

        return sprintf('%s::%s', $className ?? 'class', $constName);
    }

    private function functionName(Node $name): ?string
    {
        if ($name instanceof Name) {
            return strtolower($this->fullName($name));
        }

        return null;
    }

    private function className(ClassLike $class): ?string
    {
        if ($class->namespacedName instanceof Name) {
            return $class->namespacedName->toString();
        }

        if ($class->name instanceof Identifier) {
            return $class->name->toString();
        }

        return null;
    }

    private function classNameFromExpr(Node $class): ?string
    {
        if ($class instanceof Name) {
            return $this->fullName($class);
        }

        if ($class instanceof Expr) {
            return null;
        }

        return null;
    }

    private function attributeName(Attribute $attribute): string
    {
        return $this->fullName($attribute->name);
    }

    private function fullName(Name $name): string
    {
        if ($name->hasAttribute('resolvedName')) {
            $resolved = $name->getAttribute('resolvedName');
            if ($resolved instanceof Name) {
                return $resolved->toString();
            }
        }

        return $name->toString();
    }

    private function domainContext(string $relativePath): string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $parts = explode('/', $normalized);
        if (($parts[0] ?? null) !== 'src') {
            return 'Unknown';
        }

        return $parts[1] ?? 'Unknown';
    }

    /**
     * @return array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * }|null
     */
    private function currentScope(): ?array
    {
        if ($this->scopeStack === []) {
            return null;
        }

        return $this->scopeStack[array_key_last($this->scopeStack)];
    }

    /**
     * @param array{
     *     loggerVars: array<string, string>,
     *     className: string|null,
     *     propertyLoggers: array<string, string>,
     *     loggerProperties: array<string, bool>
     * } $scope
     */
    private function replaceCurrentScope(array $scope): void
    {
        if ($this->scopeStack === []) {
            return;
        }

        $this->scopeStack[array_key_last($this->scopeStack)] = $scope;
    }

    /**
     * @param array<int, Arg|VariadicPlaceholder> $args
     */
    private function argumentValue(array $args, int $index): ?Expr
    {
        $arg = $args[$index] ?? null;
        if (!$arg instanceof Arg) {
            return null;
        }

        return $arg->value;
    }

    private function expressionToString(Expr $expr): string
    {
        return $this->prettyPrinter->prettyPrintExpr($expr);
    }
}
