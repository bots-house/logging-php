<?php

declare(strict_types=1);

namespace Tests\Unit\Integration\OpenTelemetry\Trace;

use Adheart\Logging\Integration\OpenTelemetry\Trace\CfRayTraceContextProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class CfRayTraceContextProviderTest extends TestCase
{
    public function testReturnsCfRayFromMainRequestHeader(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/', 'GET', [], [], [], ['HTTP_CF_RAY' => '9a381d518874de92-EWR']));

        $provider = new CfRayTraceContextProvider($requestStack);

        self::assertSame(['cf_ray' => '9a381d518874de92-EWR'], $provider->provide());
    }

    public function testReturnsEmptyPayloadWhenNoMainRequest(): void
    {
        $provider = new CfRayTraceContextProvider(new RequestStack());

        self::assertSame([], $provider->provide());
    }

    public function testReturnsEmptyPayloadWhenHeaderIsMissing(): void
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/'));

        $provider = new CfRayTraceContextProvider($requestStack);

        self::assertSame([], $provider->provide());
    }
}
