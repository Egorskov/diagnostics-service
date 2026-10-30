<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Messenger;

use App\Application\Event\Incoming\TaskCompleted;
use App\Infrastructure\Messenger\DecodeFailure;
use App\Infrastructure\Messenger\EnvelopeSerializer;
use App\Infrastructure\Messenger\EventTypeRegistry;
use App\Infrastructure\Messenger\RawBodyStamp;
use App\Infrastructure\Messenger\UndecodableMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

final class EnvelopeSerializerTest extends TestCase
{
    private const EVENT_ID = '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f';
    private const CORRELATION_ID = 'c0ffee00-1111-4222-8333-444455556666';

    /**
     * @param array<string, mixed> $override
     */
    private static function body(array $override = []): string
    {
        $envelope = array_replace([
            'event_id' => self::EVENT_ID,
            'event_type' => 'task.completed',
            'event_version' => 1,
            'occurred_at' => '2026-10-30T12:00:00Z',
            'producer' => 'courses',
            'correlation_id' => self::CORRELATION_ID,
            'payload' => [
                'student_id' => '11111111-1111-4111-8111-111111111111',
                'path_id' => '22222222-2222-4222-8222-222222222222',
                'task_id' => '33333333-3333-4333-8333-333333333333',
            ],
        ], $override);

        return json_encode(array_filter($envelope, static fn (mixed $v): bool => null !== $v), \JSON_THROW_ON_ERROR);
    }

    private static function serializer(): EnvelopeSerializer
    {
        return new EnvelopeSerializer(new EventTypeRegistry());
    }

    public function testDecodesValidEvent(): void
    {
        $body = self::body();

        $envelope = self::serializer()->decode(['body' => $body, 'headers' => []]);

        $event = $envelope->getMessage();
        self::assertInstanceOf(TaskCompleted::class, $event);
        self::assertSame(self::EVENT_ID, $event->metadata()->eventId);
        self::assertSame('33333333-3333-4333-8333-333333333333', $event->taskId);
        self::assertSame($body, $envelope->last(RawBodyStamp::class)?->body);
        self::assertNull($envelope->last(RedeliveryStamp::class));
    }

    /**
     * @return iterable<string, array{string, DecodeFailure, string, ?string}>
     */
    public static function undecodable(): iterable
    {
        yield 'not json' => ['{"event_id": ', DecodeFailure::InvalidJson, 'Syntax error', null];
        yield 'json list' => ['[1, 2]', DecodeFailure::InvalidJson, 'Body must be a JSON object', null];
        yield 'json scalar' => ['"hello"', DecodeFailure::InvalidJson, 'Body must be a JSON object', null];
        yield 'empty body' => ['', DecodeFailure::InvalidJson, 'Syntax error', null];
        yield 'no event_id' => [self::body(['event_id' => null]), DecodeFailure::InvalidEnvelope, 'event_id: is required', null];
        yield 'no payload' => [self::body(['payload' => null]), DecodeFailure::InvalidEnvelope, 'payload: is required', self::EVENT_ID];
        yield 'payload not object' => [self::body(['payload' => [1, 2]]), DecodeFailure::InvalidEnvelope, 'payload: must be an object', self::EVENT_ID];
        yield 'unknown type' => [self::body(['event_type' => 'quiz.answered']), DecodeFailure::UnknownEventType, 'Unknown event type "quiz.answered"', self::EVENT_ID];
        yield 'unsupported version' => [self::body(['event_version' => 2]), DecodeFailure::UnsupportedEventVersion, 'version 2 is not supported', self::EVENT_ID];
        yield 'invalid payload' => [self::body(['payload' => ['student_id' => 'x']]), DecodeFailure::InvalidPayload, 'payload.student_id: must be a UUID', self::EVENT_ID];
    }

    #[DataProvider('undecodable')]
    public function testTurnsBrokenMessageIntoUndecodable(string $body, DecodeFailure $reason, string $detail, ?string $eventId): void
    {
        $envelope = self::serializer()->decode(['body' => $body, 'headers' => []]);

        $message = $envelope->getMessage();
        self::assertInstanceOf(UndecodableMessage::class, $message);
        self::assertSame($reason, $message->reason);
        self::assertStringContainsString($detail, $message->detail);
        self::assertSame($body, $message->rawBody);
        self::assertSame($eventId, $message->eventId);
    }

    public function testUndecodableKeepsCorrelationIdForLogs(): void
    {
        $message = self::serializer()->decode(['body' => self::body(['event_type' => 'task.complete'])])->getMessage();

        self::assertInstanceOf(UndecodableMessage::class, $message);
        self::assertSame('task.complete', $message->eventType);
        self::assertSame(self::CORRELATION_ID, $message->correlationId);
    }

    public function testRestoresRetryCountFromHeaders(): void
    {
        $envelope = self::serializer()->decode(['body' => self::body(), 'headers' => [
            EnvelopeSerializer::RETRY_COUNT_HEADER => '2',
            EnvelopeSerializer::REDELIVERED_AT_HEADER => '2026-10-30T12:00:04.000+00:00',
        ]]);

        $redelivery = $envelope->last(RedeliveryStamp::class);
        self::assertNotNull($redelivery);
        self::assertSame(2, $redelivery->getRetryCount());
        self::assertSame('2026-10-30T12:00:04+00:00', $redelivery->getRedeliveredAt()->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function foreignHeaders(): iterable
    {
        yield 'serialized stamp from producer' => [['X-Message-Stamp-'.RedeliveryStamp::class => serialize([new RedeliveryStamp(99)])]];
        yield 'retry count not a number' => [[EnvelopeSerializer::RETRY_COUNT_HEADER => 'O:8:"stdClass":0:{}']];
        yield 'negative retry count' => [[EnvelopeSerializer::RETRY_COUNT_HEADER => '-1']];
    }

    /**
     * @param array<string, mixed> $headers
     */
    #[DataProvider('foreignHeaders')]
    public function testIgnoresForeignHeaders(array $headers): void
    {
        $envelope = self::serializer()->decode(['body' => self::body(), 'headers' => $headers]);

        self::assertInstanceOf(TaskCompleted::class, $envelope->getMessage());
        self::assertNull($envelope->last(RedeliveryStamp::class));
    }
}
