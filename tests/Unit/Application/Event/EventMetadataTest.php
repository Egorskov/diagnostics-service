<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event;

use App\Application\Event\EventMetadata;
use App\Application\Event\InvalidPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventMetadataTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function envelope(): array
    {
        return [
            'event_id' => '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f',
            'event_type' => 'task.completed',
            'event_version' => 1,
            'occurred_at' => '2026-10-30T12:00:00+01:00',
            'producer' => 'courses',
            'correlation_id' => 'c0ffee00-1111-4222-8333-444455556666',
            'payload' => [],
        ];
    }

    public function testReadsEnvelope(): void
    {
        $metadata = EventMetadata::fromEnvelope(self::envelope());

        self::assertSame('0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f', $metadata->eventId);
        self::assertSame('task.completed', $metadata->eventType);
        self::assertSame(1, $metadata->eventVersion);
        self::assertSame('courses', $metadata->producer);
        self::assertSame('c0ffee00-1111-4222-8333-444455556666', $metadata->correlationId);
    }

    public function testOccurredAtIsNormalisedToUtc(): void
    {
        $occurredAt = EventMetadata::fromEnvelope(self::envelope())->occurredAt;

        self::assertSame('2026-10-30T11:00:00+00:00', $occurredAt->format(\DATE_ATOM));
        self::assertSame('UTC', $occurredAt->getTimezone()->getName());
    }

    public function testCorrelationIdIsOptional(): void
    {
        $envelope = self::envelope();
        unset($envelope['correlation_id']);

        self::assertNull(EventMetadata::fromEnvelope($envelope)->correlationId);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function invalidEnvelopes(): iterable
    {
        foreach (['event_id', 'event_type', 'event_version', 'occurred_at', 'producer'] as $field) {
            yield "no {$field}" => [$field, null, "{$field}: is required"];
        }
        yield 'event_id not uuid' => ['event_id', '42', 'event_id: must be a UUID'];
        yield 'event_version zero' => ['event_version', 0, 'event_version: must be >= 1'];
        yield 'event_version string' => ['event_version', '1', 'event_version: must be an integer'];
        yield 'occurred_at without tz' => ['occurred_at', '2026-10-30T12:00:00', 'occurred_at: must be an ISO 8601 date-time with timezone'];
        yield 'correlation_id not uuid' => ['correlation_id', 'abc', 'correlation_id: must be a UUID'];
    }

    #[DataProvider('invalidEnvelopes')]
    public function testRejectsInvalidEnvelope(string $field, mixed $value, string $message): void
    {
        $envelope = self::envelope();
        $envelope[$field] = $value;

        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage($message);

        EventMetadata::fromEnvelope($envelope);
    }
}
