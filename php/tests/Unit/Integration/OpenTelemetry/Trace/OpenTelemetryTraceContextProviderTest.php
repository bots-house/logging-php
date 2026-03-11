<?php

declare(strict_types=1);

namespace Tests\Unit\Integration\OpenTelemetry\Trace;

use Adheart\Logging\Integration\OpenTelemetry\Trace\OpenTelemetryTraceContextProvider;
use PHPUnit\Framework\TestCase;

final class OpenTelemetryTraceContextProviderTest extends TestCase
{
    public function testReturnsEmptyPayloadWhenSpanContextIsInvalid(): void
    {
        $provider = new OpenTelemetryTraceContextProvider(
            static fn (): array => ['trace_id' => '', 'span_id' => '']
        );

        self::assertSame([], $provider->provide());
    }

    public function testBuildsExpectedTraceFieldsFromSpanContext(): void
    {
        $provider = new OpenTelemetryTraceContextProvider(
            static fn (): array => [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => true,
                'trace_flags' => 1,
            ]
        );

        self::assertSame(
            [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => '01',
                'traceparent' => '00-8b730fe79387fd9b697d5563c0712d87-04c52f40924f575e-01',
            ],
            $provider->provide()
        );
    }

    public function testKeepsTraceparentFromSpanContextWhenProvided(): void
    {
        $provider = new OpenTelemetryTraceContextProvider(
            static fn (): array => [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'trace_flags' => 0,
                'traceparent' => '00-custom-traceparent',
            ]
        );

        self::assertSame(
            [
                'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                'span_id' => '04c52f40924f575e',
                'sampled' => '00',
                'traceparent' => '00-custom-traceparent',
            ],
            $provider->provide()
        );
    }
}
