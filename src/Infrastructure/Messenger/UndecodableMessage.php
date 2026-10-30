<?php

declare(strict_types=1);

namespace App\Infrastructure\Messenger;

/**
 * Входящее сообщение, которое не удалось разобрать в событие (ADR 0002, п. 3).
 *
 * Вместо reject сериализатор возвращает его как обычное сообщение, чтобы оно попало
 * в failure transport с исходным телом и причиной. Идентификаторы — то, что удалось
 * вытащить из тела, для логов; им нельзя доверять.
 */
final readonly class UndecodableMessage
{
    public function __construct(
        public string $rawBody,
        public DecodeFailure $reason,
        public string $detail,
        public ?string $eventId = null,
        public ?string $eventType = null,
        public ?string $correlationId = null,
    ) {
    }
}
