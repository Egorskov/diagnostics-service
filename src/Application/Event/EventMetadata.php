<?php

declare(strict_types=1);

namespace App\Application\Event;

/**
 * Метаданные общего конверта события (asyncapi.yaml → Envelope).
 */
final readonly class EventMetadata
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public int $eventVersion,
        /** Момент события у отправителя, UTC */
        public \DateTimeImmutable $occurredAt,
        public string $producer,
        public ?string $correlationId = null,
    ) {
    }

    /**
     * @param array<mixed> $envelope разобранный JSON конверта
     *
     * @throws InvalidPayload
     */
    public static function fromEnvelope(array $envelope): self
    {
        $reader = new PayloadReader($envelope);

        return new self(
            eventId: $reader->uuid('event_id'),
            eventType: $reader->string('event_type'),
            eventVersion: $reader->int('event_version', min: 1),
            occurredAt: $reader->dateTime('occurred_at'),
            producer: $reader->string('producer'),
            correlationId: $reader->optionalUuid('correlation_id'),
        );
    }
}
