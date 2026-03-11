<?php

declare(strict_types=1);

namespace Adheart\Logging\Core\Formatters;

use DateTimeZone;
use Monolog\Formatter\JsonFormatter;

/**
 * Builds the final JSON log using a unified schema:
 *
 * {
 *   "timestamp": "2025-10-06T07:14:31.123Z",
 *   "level": { "level": 400, "severity": "ERROR" },
 *   "message": "User not found",
 *   "context": { ...context + extra... },
 *   "service": { "name": "...", "version": "...", "module": "..." },
 *   "trace": { "id": "...", "cf_ray_id": "...", ... },
 *   "version": "1.0.0"
 * }
 */
final class SchemaFormatterV1 extends JsonFormatter
{
    /** @var string */
    private $schemaVersion;
    /** @var string|null */
    private $serviceName;
    /** @var string|null */
    private $serviceVersion;

    /**
     * @psalm-param self::BATCH_MODE_JSON|self::BATCH_MODE_NEWLINES $batchMode
     */
    public function __construct(
        string $schemaVersion = '1.0.0',
        ?string $serviceName = null,
        ?string $serviceVersion = null,
        int $batchMode = self::BATCH_MODE_JSON,
        bool $appendNewline = true
    ) {
        /** @var 1|2 $batchMode */
        parent::__construct($batchMode, $appendNewline);
        $this->schemaVersion = $schemaVersion;
        $this->serviceName = $serviceName;
        $this->serviceVersion = $serviceVersion;
    }

    #[\Override]
    public function format(array $record): string
    {
        $timestamp = $this->formatTimestamp($record);

        $level = [
            'level' => isset($record['level']) ? (int)$record['level'] : null,
            'severity' => isset($record['level_name']) ? (string)$record['level_name'] : null,
        ];

        $context = $record['context'];
        $extra = $record['extra'];

        $service = $this->extractObject('service', $context, $extra);
        if ($service === [] && ($this->serviceName !== null || $this->serviceVersion !== null)) {
            $service = [
                'name' => $this->serviceName,
                'version' => $this->serviceVersion,
                'channel' => isset($record['channel']) ? (string)$record['channel'] : '',
            ];
        }
        $trace = $this->extractTraceObject($context, $extra);
        $trace = $trace !== [] ? $trace : (object)[];

        $context['extra'] = $context['extra'] ?? [] + $extra;

        $normalizedContext = $this->normalize($context);
        $normalizedService = $this->normalize($service);
        $normalizedTrace = $this->normalize($trace);

        $message = isset($record['message']) ? (string)$record['message'] : '';

        $data = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'context' => $normalizedContext,
            'service' => $normalizedService,
            'trace' => $normalizedTrace,
            'version' => $this->schemaVersion,
        ];

        return $this->toJson($data, true) . ($this->appendNewline ? "\n" : '');
    }

    /**
     * Extracts an object by key from context or extra:
     * - if present in both context and extra, context has priority
     * - removes this key from both arrays
     *
     * @param string $key
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    private function extractObject(string $key, array &$context, array &$extra): array
    {
        if (isset($context[$key]) && \is_array($context[$key])) {
            $value = $context[$key];
            unset($context[$key]);

            return $value;
        }

        if (isset($extra[$key]) && \is_array($extra[$key])) {
            $value = $extra[$key];
            unset($extra[$key]);

            return $value;
        }

        return [];
    }

    /**
     * Extracts only trace-context payload (trace_id/span_id/traceparent/cf_ray/...)
     * from context/extra. Non-trace arrays (e.g. backtrace frames) stay in context.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $extra
     *
     * @return array<array-key,mixed>
     */
    private function extractTraceObject(array &$context, array &$extra): array
    {
        /** @var array<array-key,mixed>|null $contextTrace */
        $contextTrace = null;
        if (isset($context['trace']) && \is_array($context['trace'])) {
            $contextTrace = $context['trace'];
            unset($context['trace']);
        }

        /** @var array<array-key,mixed>|null $extraTrace */
        $extraTrace = null;
        if (isset($extra['trace']) && \is_array($extra['trace'])) {
            $extraTrace = $extra['trace'];
            unset($extra['trace']);
        }

        if ($this->looksLikeTraceContext($contextTrace) && $contextTrace !== null) {
            return $contextTrace;
        }

        if ($this->looksLikeTraceContext($extraTrace) && $extraTrace !== null) {
            if ($contextTrace !== null) {
                $context['trace'] = $contextTrace;
            }

            return $extraTrace;
        }

        if ($contextTrace !== null) {
            $context['trace'] = $contextTrace;
        }

        if ($extraTrace !== null) {
            $extra['trace'] = $extraTrace;
        }

        return [];
    }

    /**
     * @param array<mixed>|null $trace
     */
    private function looksLikeTraceContext(?array $trace): bool
    {
        if ($trace === null) {
            return false;
        }

        foreach (['trace_id', 'span_id', 'traceparent', 'sampled', 'cf_ray'] as $key) {
            if (array_key_exists($key, $trace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Formats datetime as 2025-10-06T07:14:31.123Z
     *
     * @param array<string,mixed> $record
     */
    private function formatTimestamp(array $record): string
    {
        if (!isset($record['datetime']) || !$record['datetime'] instanceof \DateTimeImmutable) {
            $record['datetime'] = new \DateTimeImmutable('now', new DateTimeZone('UTC'));
        }

        $dt = $record['datetime'];

        $utc = $dt->setTimezone(new DateTimeZone('UTC'));

        return $utc->format('Y-m-d\TH:i:s.v\Z');
    }
}
