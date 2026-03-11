<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Formatters;

use Adheart\Logging\Core\Formatters\SchemaFormatterV1;
use PHPUnit\Framework\TestCase;

final class AdheartFormatterV1Test extends TestCase
{
    public function testKeepsContextTraceFramesAndUsesTraceContextFromExtra(): void
    {
        $formatter = new SchemaFormatterV1('1.0');

        $record = [
            'message' => 'test',
            'context' => [
                'trace' => [
                    ['file' => '/app/src/Foo.php', 'line' => 10],
                    ['file' => '/app/src/Bar.php', 'line' => 20],
                ],
            ],
            'extra' => [
                'trace' => [
                    'trace_id' => '8b730fe79387fd9b697d5563c0712d87',
                    'span_id' => '04c52f40924f575e',
                    'sampled' => '01',
                    'traceparent' => '00-8b730fe79387fd9b697d5563c0712d87-04c52f40924f575e-01',
                ],
            ],
            'channel' => 'app',
            'level' => 300,
            'level_name' => 'WARNING',
            'datetime' => new \DateTimeImmutable('2026-03-11T13:17:19.308Z'),
        ];

        $json = $formatter->format($record);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('8b730fe79387fd9b697d5563c0712d87', $decoded['trace']['trace_id']);
        self::assertSame('04c52f40924f575e', $decoded['trace']['span_id']);

        self::assertArrayHasKey('trace', $decoded['context']);
        self::assertSame('/app/src/Foo.php', $decoded['context']['trace'][0]['file']);
        self::assertSame([], $decoded['context']['extra']);
    }
}
