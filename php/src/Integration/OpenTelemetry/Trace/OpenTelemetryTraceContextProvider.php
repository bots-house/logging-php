<?php

declare(strict_types=1);

namespace Adheart\Logging\Integration\OpenTelemetry\Trace;

use Adheart\Logging\Core\Trace\TraceContextProviderInterface;

/**
 * @psalm-type SpanContextPayload = array{
 *     trace_id?: mixed,
 *     span_id?: mixed,
 *     sampled?: mixed,
 *     trace_flags?: mixed,
 *     traceparent?: mixed
 * }
 * @psalm-type SpanContextReader = callable(): SpanContextPayload
 */
final class OpenTelemetryTraceContextProvider implements TraceContextProviderInterface
{
    /** @var SpanContextReader */
    private $spanContextReader;

    /**
     * @param SpanContextReader|null $spanContextReader Reader of current span context.
     */
    public function __construct(?callable $spanContextReader = null)
    {
        $this->spanContextReader = $spanContextReader ?? fn (): array => $this->readFromOpenTelemetry();
    }

    #[\Override]
    public function provide(): array
    {
        $context = ($this->spanContextReader)();

        $traceId = isset($context['trace_id']) && is_string($context['trace_id']) ? $context['trace_id'] : '';
        $spanId = isset($context['span_id']) && is_string($context['span_id']) ? $context['span_id'] : '';

        if ($traceId === '' || $spanId === '') {
            return [];
        }

        $traceFlags = isset($context['trace_flags']) && \is_int($context['trace_flags'])
            ? $context['trace_flags']
            : null;
        $sampled = isset($context['sampled']) && \is_bool($context['sampled'])
            ? $context['sampled']
            : null;

        if ($sampled === null && $traceFlags !== null) {
            $sampled = ($traceFlags & 0x01) === 0x01;
        }

        $traceparentValue = $context['traceparent'] ?? '';
        $traceparent = \is_string($traceparentValue) && $traceparentValue !== ''
            ? $traceparentValue
            : null;

        if ($traceparent === null) {
            $flagsHex = str_pad(dechex($traceFlags ?? ($sampled === true ? 1 : 0)), 2, '0', STR_PAD_LEFT);
            $traceparent = sprintf('00-%s-%s-%s', $traceId, $spanId, $flagsHex);
        }

        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'sampled' => $sampled === true ? '01' : '00',
            'traceparent' => $traceparent,
        ];
    }

    /**
     * @return SpanContextPayload
     */
    private function readFromOpenTelemetry(): array
    {
        if (!class_exists('OpenTelemetry\\API\\Trace\\Span') || !class_exists('OpenTelemetry\\Context\\Context')) {
            return [];
        }

        /** @var class-string $contextClass */
        $contextClass = 'OpenTelemetry\\Context\\Context';
        /** @var class-string $spanClass */
        $spanClass = 'OpenTelemetry\\API\\Trace\\Span';

        $span = $spanClass::fromContext($contextClass::getCurrent());
        $spanContext = $span->getContext();

        if (!$spanContext->isValid()) {
            return [];
        }

        return [
            'trace_id' => $spanContext->getTraceId(),
            'span_id' => $spanContext->getSpanId(),
            'sampled' => $spanContext->isSampled(),
            'trace_flags' => $spanContext->getTraceFlags(),
        ];
    }
}
