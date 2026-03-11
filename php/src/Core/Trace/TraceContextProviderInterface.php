<?php

declare(strict_types=1);

namespace Adheart\Logging\Core\Trace;

interface TraceContextProviderInterface
{
    /**
     * @return array<string,mixed>
     */
    public function provide(): array;
}
