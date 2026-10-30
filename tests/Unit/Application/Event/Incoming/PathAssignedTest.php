<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Event\Incoming;

use App\Application\Event\Incoming\PathAssigned;
use App\Application\Event\Incoming\TaskKind;
use App\Application\Event\InvalidPayload;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PathAssignedTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $tasks
     *
     * @return array<string, mixed>
     */
    private static function snapshot(array $tasks): array
    {
        return [
            'student_id' => EventFixture::STUDENT,
            'path_id' => EventFixture::PATH,
            'mentor_id' => EventFixture::MENTOR,
            'started_at' => '2026-10-05T07:00:00Z',
            'weekly_goal_minutes' => 1500,
            'tasks' => $tasks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function task(int $position, int $estimate = 60, ?string $kind = null): array
    {
        return array_filter([
            'task_id' => \sprintf('33333333-3333-4333-8333-%012d', $position),
            'stage_id' => EventFixture::STAGE,
            'estimate_minutes' => $estimate,
            'position' => $position,
            'kind' => $kind,
        ], static fn (mixed $v): bool => null !== $v);
    }

    public function testReadsSnapshotWithTasks(): void
    {
        $event = PathAssigned::fromPayload(EventFixture::metadata('path.assigned'), EventFixture::payload(self::snapshot([
            self::task(1, 90, 'theory'),
            self::task(2, 120, 'sandbox'),
            self::task(3, 30),
        ])));

        self::assertSame(EventFixture::MENTOR, $event->mentorId);
        self::assertSame('2026-10-05T07:00:00+00:00', $event->startedAt->format(\DATE_ATOM));
        self::assertSame(1500, $event->weeklyGoalMinutes);
        self::assertCount(3, $event->tasks);
        self::assertSame('33333333-3333-4333-8333-000000000002', $event->tasks[1]->taskId);
        self::assertSame(EventFixture::STAGE, $event->tasks[1]->stageId);
        self::assertSame(120, $event->tasks[1]->estimateMinutes);
        self::assertSame(2, $event->tasks[1]->position);
        self::assertSame(TaskKind::Sandbox, $event->tasks[1]->kind);
        self::assertNull($event->tasks[2]->kind);
    }

    public function testAcceptsEmptyTaskList(): void
    {
        $event = PathAssigned::fromPayload(EventFixture::metadata('path.assigned'), EventFixture::payload(self::snapshot([])));

        self::assertSame([], $event->tasks);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidSnapshots(): iterable
    {
        yield 'estimate below 1 in the middle' => [
            self::snapshot([self::task(1), self::task(2, 0), self::task(3)]),
            'payload.tasks[1].estimate_minutes: must be >= 1',
        ];
        yield 'unknown kind' => [
            self::snapshot([self::task(1, kind: 'podcast')]),
            'payload.tasks[0].kind: must be one of:',
        ];
        yield 'task without stage' => [
            self::snapshot([array_diff_key(self::task(1), ['stage_id' => true])]),
            'payload.tasks[0].stage_id: is required',
        ];
        yield 'no tasks' => [
            array_diff_key(self::snapshot([]), ['tasks' => true]),
            'payload.tasks: is required',
        ];
        yield 'no mentor' => [
            array_diff_key(self::snapshot([]), ['mentor_id' => true]),
            'payload.mentor_id: is required',
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    #[DataProvider('invalidSnapshots')]
    public function testRejectsInvalidSnapshot(array $snapshot, string $message): void
    {
        $this->expectException(InvalidPayload::class);
        $this->expectExceptionMessage($message);

        PathAssigned::fromPayload(EventFixture::metadata('path.assigned'), EventFixture::payload($snapshot));
    }
}
