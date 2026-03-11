<?php

declare(strict_types=1);

namespace Adheart\Logging\Core\Processors;

final class MessageNormalizerProcessor
{
    private const NORMALIZED_JSON_MESSAGE = '[json moved to context.message_json]';

    public function __invoke(array $record): array
    {
        $message = isset($record['message']) && \is_string($record['message'])
            ? $record['message']
            : (string)($record['message'] ?? '');
        $context = isset($record['context']) && is_array($record['context']) ? $record['context'] : [];

        if (preg_match('/^\s*[\{\[]/', $message) === 1) {
            $context['message_json'] = $message;
            $message = self::NORMALIZED_JSON_MESSAGE;
        }

        $record['message'] = $message;
        $record['context'] = $context;

        return $record;
    }
}
