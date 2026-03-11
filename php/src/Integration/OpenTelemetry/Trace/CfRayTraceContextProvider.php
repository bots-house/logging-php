<?php

declare(strict_types=1);

namespace Adheart\Logging\Integration\OpenTelemetry\Trace;

use Adheart\Logging\Core\Trace\TraceContextProviderInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final class CfRayTraceContextProvider implements TraceContextProviderInterface
{
    public function __construct(private RequestStack $requestStack)
    {
    }

    #[\Override]
    public function provide(): array
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            return [];
        }

        $cfRay = $request->headers->get('cf-ray');
        if (!is_string($cfRay) || $cfRay === '') {
            return [];
        }

        return ['cf_ray' => $cfRay];
    }
}
