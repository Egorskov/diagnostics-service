<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Event\EventMetadata;
use App\Application\Event\Incoming\TaskCompleted;
use App\Application\Event\Incoming\TaskStarted;
use App\Application\Handler\TaskCompletedHandler;
use App\Application\Handler\TaskStartedHandler;
use App\Domain\Progress\ActivitySource;
use App\Domain\Progress\TaskProgress;
use App\Domain\Progress\TaskStatus;
use App\Tests\Support\InMemoryTaskProgressRepository;
use App\Tests\Support\RecordingActivityTracker;
use App\Tests\Support\RecordingTaskStuckCheck;
use PHPUnit\Framework\TestCase;

final class TaskEventHandlersTest extends TestCase
{
    private const AT = '2026-10-30T09:00:00Z';

    private InMemoryTaskProgressRepository $progress;
    private RecordingActivityTracker $activity;
    private RecordingTaskStuckCheck $stuck;

    protected function setUp(): void
    {
        $this->progress = new InMemoryTaskProgressRepository();
        $this->activity = new RecordingActivityTracker();
        $this->stuck = new RecordingTaskStuckCheck();
    }

    private function started(string $at = self::AT): void
    {
        $metadata = new EventMetadata('event', 'task.started', 1, new \DateTimeImmutable($at), 'courses');
        (new TaskStartedHandler($this->progress, $this->activity))(new TaskStarted($metadata, 'student', 'event-path', 'task'));
    }

    private function completed(string $at = self::AT): void
    {
        $metadata = new EventMetadata('event', 'task.completed', 1, new \DateTimeImmutable($at), 'courses');
        (new TaskCompletedHandler($this->progress, $this->activity, $this->stuck))(new TaskCompleted($metadata, 'student', 'event-path', 'task'));
    }

    private function progress(): TaskProgress
    {
        return $this->progress->findForUpdate('student', 'task') ?? self::fail('Прогресс не создан');
    }

    public function testStartMovesExistingProgress(): void
    {
        $this->progress->add(new TaskProgress('student', 'task', 'assigned-path'));

        $this->started();

        self::assertSame(TaskStatus::InProgress, $this->progress()->status());
        self::assertEquals(new \DateTimeImmutable(self::AT), $this->progress()->startedAt());
        self::assertSame('assigned-path', $this->progress()->pathId());
    }

    public function testCompleteMovesExistingProgress(): void
    {
        $this->started('2026-10-30T08:00:00Z');

        $this->completed();

        self::assertSame(TaskStatus::Completed, $this->progress()->status());
        self::assertEquals(new \DateTimeImmutable(self::AT), $this->progress()->completedAt());
        self::assertEquals(new \DateTimeImmutable('2026-10-30T08:00:00Z'), $this->progress()->startedAt());
    }

    public function testStartBeforePathAssignmentCreatesProgressWithPathFromEvent(): void
    {
        $this->started();

        self::assertSame('event-path', $this->progress()->pathId());
        self::assertSame(TaskStatus::InProgress, $this->progress()->status());
    }

    public function testCompleteWithoutStartCreatesProgressWithPathFromEvent(): void
    {
        $this->completed();

        self::assertSame('event-path', $this->progress()->pathId());
        self::assertSame(TaskStatus::Completed, $this->progress()->status());
        self::assertNull($this->progress()->startedAt());
    }

    public function testCompleteBeforeStartEndsCompleted(): void
    {
        $this->completed();
        $this->started('2026-10-30T08:00:00Z');

        self::assertSame(TaskStatus::Completed, $this->progress()->status());
        self::assertNotNull($this->progress()->startedAt());
        self::assertNotNull($this->progress()->completedAt());
    }

    public function testBothEventsAreActivityPoints(): void
    {
        $this->started();
        $this->completed();

        self::assertCount(2, $this->activity->points);
        foreach ($this->activity->points as $point) {
            self::assertSame('student', $point->studentId);
            self::assertSame('task', $point->taskId);
            self::assertSame(ActivitySource::Other, $point->source);
            self::assertEquals(new \DateTimeImmutable(self::AT), $point->occurredAt);
        }
    }

    public function testOnlyCompletionChecksStuck(): void
    {
        $this->started();
        self::assertSame([], $this->stuck->checks);

        $this->completed();

        self::assertSame([['student', 'task', 'event']], $this->stuck->checks);
    }
}
