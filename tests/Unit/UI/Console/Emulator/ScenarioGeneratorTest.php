<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Console\Emulator;

use App\Infrastructure\Messenger\EnvelopeSerializer;
use App\Infrastructure\Messenger\EventTypeRegistry;
use App\Infrastructure\Messenger\UndecodableMessage;
use App\Tests\Support\FixedThresholds;
use App\UI\Console\Emulator\EmulatedEvent;
use App\UI\Console\Emulator\EmulatedStream;
use App\UI\Console\Emulator\Profile;
use App\UI\Console\Emulator\Scenario;
use App\UI\Console\Emulator\ScenarioGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScenarioGeneratorTest extends TestCase
{
    /** Среда 18:12 по Белграду */
    private const NOW = '2026-11-04T17:12:00Z';

    private static function generate(int $days = 14, int $seed = 42, string $now = self::NOW): EmulatedStream
    {
        return (new ScenarioGenerator(new FixedThresholds(), 'Europe/Belgrade'))->generate(new Scenario(Profile::Steady, $days, $seed), new \DateTimeImmutable($now));
    }

    /**
     * @return list<EmulatedEvent>
     */
    private static function ofType(EmulatedStream $stream, string $type): array
    {
        return array_values(array_filter($stream->events, static fn (EmulatedEvent $event): bool => $event->type === $type));
    }

    public function testStartsWithPathAssignedDaysAgo(): void
    {
        $stream = self::generate();

        $first = $stream->events[0];
        self::assertSame('path.assigned', $first->type);
        self::assertSame('2026-10-21T07:00:00Z', $first->payload['started_at']);
        self::assertSame($stream->studentId, $first->payload['student_id']);
        self::assertSame($stream->pathId, $first->payload['path_id']);
        self::assertSame($stream->mentorId, $first->payload['mentor_id']);
        self::assertCount(1, self::ofType($stream, 'path.assigned'));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function histories(): iterable
    {
        yield '14 дней, вечер среды' => [14, self::NOW];
        yield 'один день, раннее утро' => [1, '2026-11-04T05:00:00Z'];
        yield '56 дней' => [56, self::NOW];
    }

    #[DataProvider('histories')]
    public function testEventsAreOrderedAndNotInFuture(int $days, string $now): void
    {
        $stream = self::generate($days, now: $now);

        $previous = null;
        foreach ($stream->events as $event) {
            self::assertLessThanOrEqual(new \DateTimeImmutable($now), $event->occurredAt);
            if (null !== $previous) {
                self::assertGreaterThanOrEqual($previous, $event->occurredAt);
            }
            $previous = $event->occurredAt;
        }
        self::assertNotEmpty(self::ofType($stream, 'activity.heartbeat'));
    }

    public function testTaskStartedBeforeCompletedAndSandboxRunsOnSandboxTasks(): void
    {
        $stream = self::generate();
        $kinds = array_column($stream->events[0]->payload['tasks'], 'kind', 'task_id');

        $started = [];
        foreach ($stream->events as $event) {
            $task = $event->payload['task_id'] ?? null;
            match ($event->type) {
                'task.started' => $started[$task] = true,
                'task.completed' => self::assertArrayHasKey($task, $started),
                'sandbox.run' => self::assertSame('sandbox', $kinds[$task]),
                default => null,
            };
        }
        self::assertNotEmpty(self::ofType($stream, 'task.completed'));
        self::assertNotEmpty(self::ofType($stream, 'sandbox.run'));
    }

    public function testHeartbeatEverySixtySecondsWithinSession(): void
    {
        $heartbeats = self::ofType(self::generate(), 'activity.heartbeat');

        $gaps = [];
        for ($i = 1, $count = \count($heartbeats); $i < $count; ++$i) {
            $gap = $heartbeats[$i]->occurredAt->getTimestamp() - $heartbeats[$i - 1]->occurredAt->getTimestamp();
            if ($gap < 15 * 60) {
                $gaps[$gap] = true;
            }
        }
        self::assertSame([60], array_keys($gaps));
    }

    public function testTimeLoggedMatchesActiveMinutesOfSession(): void
    {
        $stream = self::generate();

        $heartbeats = [];
        foreach (self::ofType($stream, 'activity.heartbeat') as $event) {
            $key = $event->occurredAt->setTimezone(new \DateTimeZone('Europe/Belgrade'))->format('Y-m-d').'|'.$event->payload['task_id'];
            $heartbeats[$key] = ($heartbeats[$key] ?? 0) + 1;
        }
        $logged = [];
        foreach (self::ofType($stream, 'time.logged') as $event) {
            if (isset($event->payload['task_id'])) {
                $logged[$event->payload['logged_for_date'].'|'.$event->payload['task_id']] = $event->payload['minutes'];
            } else {
                self::assertSame(['interview', 30], [$event->payload['category'], $event->payload['minutes']]);
            }
        }
        ksort($heartbeats);
        ksort($logged);
        self::assertSame($heartbeats, $logged);
    }

    public function testEventIdsAreUniqueAndEveryEventDecodes(): void
    {
        $stream = self::generate();
        $serializer = new EnvelopeSerializer(new EventTypeRegistry());

        $ids = array_map(static fn (EmulatedEvent $event): string => $event->eventId, $stream->events);
        self::assertSame($ids, array_values(array_unique($ids)));
        foreach ($stream->events as $event) {
            $message = $serializer->decode(['body' => $event->json(), 'headers' => []])->getMessage();
            self::assertNotInstanceOf(UndecodableMessage::class, $message, $event->json());
            self::assertSame(['event_id', 'event_type', 'event_version', 'occurred_at', 'producer', 'payload'], array_keys($event->envelope()));
            self::assertSame('emulator', $event->envelope()['producer']);
        }
    }

    public function testSameSeedGivesSameStream(): void
    {
        $json = static fn (EmulatedStream $stream): array => array_map(static fn (EmulatedEvent $event): string => $event->json(), $stream->events);

        self::assertSame($json(self::generate(seed: 7)), $json(self::generate(seed: 7)));
        self::assertNotSame(self::generate(seed: 7)->studentId, self::generate(seed: 8)->studentId);
    }

    public function testGivenIdsAreUsed(): void
    {
        $student = '11111111-1111-4111-8111-111111111111';
        $mentor = '44444444-4444-4444-8444-444444444444';

        $stream = (new ScenarioGenerator(new FixedThresholds(), 'Europe/Belgrade'))->generate(new Scenario(Profile::Steady, 3, 1, $student, $mentor), new \DateTimeImmutable(self::NOW));

        self::assertSame([$student, $mentor], [$stream->studentId, $stream->mentorId]);
        foreach ($stream->events as $event) {
            self::assertSame($student, $event->payload['student_id']);
        }
    }

    public function testDaysOutOfRangeAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Scenario(Profile::Steady, 57, 1);
    }
}
