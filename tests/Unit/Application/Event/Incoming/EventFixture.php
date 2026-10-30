<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event\Incoming;

use App\Application\Event\EventMetadata;
use App\Application\Event\PayloadReader;

final class EventFixture
{
    public const STUDENT = '11111111-1111-4111-8111-111111111111';
    public const PATH = '22222222-2222-4222-8222-222222222222';
    public const TASK = '33333333-3333-4333-8333-333333333333';
    public const MENTOR = '44444444-4444-4444-8444-444444444444';
    public const STAGE = '55555555-5555-4555-8555-555555555555';
    public const RUN = '66666666-6666-4666-8666-666666666666';

    public static function metadata(string $eventType): EventMetadata
    {
        return new EventMetadata(
            eventId: '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f',
            eventType: $eventType,
            eventVersion: 1,
            occurredAt: new \DateTimeImmutable('2026-10-30T12:00:00Z'),
            producer: 'courses',
        );
    }

    /**
     * Payload читается с путём `payload`, как его отдаёт сериализатор.
     *
     * @param array<mixed> $payload
     */
    public static function payload(array $payload): PayloadReader
    {
        return (new PayloadReader(['payload' => $payload]))->object('payload');
    }
}
