<?php

declare(strict_types=1);

namespace App\Application\Signal;

use App\Application\Event\EventMetadata;
use App\Domain\Signal\SignalType;
use Symfony\Component\Uid\Uuid;

/**
 * Сигнал в конверте asyncapi.yaml → Envelope (ADR 0005, п. 5). Лежит в outbox, пока relay его не опубликует.
 */
final readonly class OutgoingSignal
{
    public const PRODUCER = 'diagnostics';
    public const VERSION = 1;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        public string $eventId,
        public SignalType $type,
        public \DateTimeImmutable $occurredAt,
        public ?string $correlationId,
        public array $payload,
    ) {
    }

    /**
     * @param array<string, mixed> $payload схема сигнала из asyncapi.yaml
     * @param EventMetadata|null   $cause   входящее событие, вызвавшее сигнал; null — проверка расписания
     */
    public static function create(SignalType $type, array $payload, \DateTimeImmutable $occurredAt, ?EventMetadata $cause = null): self
    {
        return new self(
            Uuid::v7()->toRfc4122(),
            $type,
            $occurredAt->setTimezone(new \DateTimeZone('UTC')),
            null === $cause ? null : ($cause->correlationId ?? $cause->eventId),
            $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        $envelope = [
            'event_id' => $this->eventId,
            'event_type' => $this->type->value,
            'event_version' => self::VERSION,
            'occurred_at' => $this->occurredAt->format(\DATE_RFC3339),
            'producer' => self::PRODUCER,
        ];
        if (null !== $this->correlationId) {
            $envelope['correlation_id'] = $this->correlationId;
        }
        $envelope['payload'] = $this->payload;

        return $envelope;
    }
}
