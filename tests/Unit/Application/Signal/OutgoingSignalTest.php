<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Signal;

use App\Application\Event\EventMetadata;
use App\Application\Signal\OutgoingSignal;
use App\Domain\Signal\SignalType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class OutgoingSignalTest extends TestCase
{
    private const EVENT = '77777777-7777-4777-8777-777777777777';
    private const CORRELATION = '88888888-8888-4888-8888-888888888888';

    #[DataProvider('causes')]
    public function testEnvelope(?EventMetadata $cause, ?string $correlationId): void
    {
        $payload = ['audience' => 'student', 'student_id' => 's1'];

        $envelope = OutgoingSignal::create(SignalType::TaskStuck, $payload, new \DateTimeImmutable('2026-11-03T12:00:00+01:00'), $cause)->envelope();

        self::assertIsString($envelope['event_id']);
        self::assertInstanceOf(UuidV7::class, Uuid::fromString($envelope['event_id']));
        self::assertSame('diagnostics.task.stuck', $envelope['event_type']);
        self::assertSame(1, $envelope['event_version']);
        self::assertSame('2026-11-03T11:00:00+00:00', $envelope['occurred_at']);
        self::assertSame('diagnostics', $envelope['producer']);
        self::assertSame($correlationId, $envelope['correlation_id'] ?? null);
        self::assertSame(null !== $correlationId, \array_key_exists('correlation_id', $envelope));
        self::assertSame($payload, $envelope['payload']);
    }

    /**
     * @return iterable<string, array{?EventMetadata, ?string}>
     */
    public static function causes(): iterable
    {
        $at = new \DateTimeImmutable('2026-11-03T10:00:00Z');
        yield 'событие с correlation_id' => [new EventMetadata(self::EVENT, 'sandbox.run', 1, $at, 'courses', self::CORRELATION), self::CORRELATION];
        yield 'событие без correlation_id' => [new EventMetadata(self::EVENT, 'sandbox.run', 1, $at, 'courses'), self::EVENT];
        yield 'проверка расписания' => [null, null];
    }

    public function testEachSignalGetsNewId(): void
    {
        $at = new \DateTimeImmutable('2026-11-03T10:00:00Z');

        self::assertNotSame(
            OutgoingSignal::create(SignalType::StudentInactive, [], $at)->eventId,
            OutgoingSignal::create(SignalType::StudentInactive, [], $at)->eventId,
        );
    }
}
