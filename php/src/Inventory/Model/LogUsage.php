<?php

declare(strict_types=1);

namespace Adheart\Logging\Inventory\Model;

final class LogUsage
{
    public function __construct(
        public readonly string $message,
        public readonly string $messageType,
        public readonly string $severity,
        public readonly string $loggerName,
        public readonly string $channel,
        /** @var string[] */
        public readonly array $contextKeys,
        public readonly string $context,
        public readonly string $path,
        public readonly int $line,
        public readonly string $domain
    ) {
    }

    public function location(): string
    {
        return sprintf('%s:%d', $this->path, $this->line);
    }
}
