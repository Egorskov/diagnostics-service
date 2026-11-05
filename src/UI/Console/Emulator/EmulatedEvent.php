<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

/**
 * Входящее событие в конверте asyncapi.yaml → Envelope и транспорт, через exchange которого оно публикуется.
 */
final readonly class EmulatedEvent
{
    public const PRODUCER = 'emulator';

    private const TRANSPORTS = [
        'path.assigned' => 'courses',
        'task.started' => 'courses',
        'task.completed' => 'courses',
        'sandbox.run' => 'courses',
        'time.logged' => 'time',
        'activity.heartbeat' => 'heartbeat',
    ];

    public string $transport;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $eventId,
        public string $type,
        public \DateTimeImmutable $occurredAt,
        public array $payload,
    ) {
        $this->transport = self::TRANSPORTS[$type] ?? throw new \InvalidArgumentException(\sprintf('Event type %s is not emulated', $type));
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_type' => $this->type,
            'event_version' => 1,
            'occurred_at' => $this->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            'producer' => self::PRODUCER,
            'payload' => $this->payload,
        ];
    }

    public function json(): string
    {
        return json_encode($this->envelope(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
