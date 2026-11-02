<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Event\EventMetadata;
use App\Application\Event\Incoming\PathAssigned;
use App\Application\Event\Incoming\PathTask as SnapshotTask;
use App\Application\Handler\PathAssignedHandler;
use App\Domain\Progress\TaskProgress;
use App\Domain\Progress\TaskStatus;
use App\Tests\Support\InMemoryPathTaskRepository;
use App\Tests\Support\InMemoryStudentPathRepository;
use App\Tests\Support\InMemoryTaskProgressRepository;
use PHPUnit\Framework\TestCase;

final class PathAssignedHandlerTest extends TestCase
{
    private InMemoryStudentPathRepository $paths;
    private InMemoryPathTaskRepository $tasks;
    private InMemoryTaskProgressRepository $progress;
    private PathAssignedHandler $handler;

    protected function setUp(): void
    {
        $this->paths = new InMemoryStudentPathRepository();
        $this->tasks = new InMemoryPathTaskRepository();
        $this->progress = new InMemoryTaskProgressRepository();
        $this->handler = new PathAssignedHandler($this->paths, $this->tasks, $this->progress);
    }

    /**
     * @param list<SnapshotTask> $tasks
     */
    private static function event(array $tasks, string $mentorId = 'mentor', string $studentId = 'student'): PathAssigned
    {
        $occurredAt = new \DateTimeImmutable('2026-10-30T09:00:00Z');
        $metadata = new EventMetadata('event', 'path.assigned', 1, $occurredAt, 'courses');

        return new PathAssigned($metadata, $studentId, 'path', $mentorId, new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500, $tasks);
    }

    private static function task(string $taskId, int $estimate = 60, int $position = 1): SnapshotTask
    {
        return new SnapshotTask($taskId, 'stage', $estimate, $position);
    }

    /**
     * @return array<string, int> task_id => estimate_minutes
     */
    private function estimates(): array
    {
        $estimates = [];
        foreach ($this->tasks->forPath('path') as $task) {
            $estimates[$task->taskId()] = $task->estimateMinutes();
        }

        return $estimates;
    }

    public function testFirstAssignment(): void
    {
        ($this->handler)(self::event([self::task('t1', 60, 1), self::task('t2', 90, 2), self::task('t3', 30, 3)]));

        $path = $this->paths->find('student', 'path');
        self::assertNotNull($path);
        self::assertSame('mentor', $path->mentorId());
        self::assertSame(1500, $path->weeklyGoalMinutes());
        self::assertSame(['t1' => 60, 't2' => 90, 't3' => 30], $this->estimates());

        self::assertCount(3, $this->progress->progress);
        foreach ($this->progress->progress as $progress) {
            self::assertSame(TaskStatus::NotStarted, $progress->status());
            self::assertSame('path', $progress->pathId());
            self::assertSame(0, $progress->activeMinutes());
            self::assertSame(0, $progress->failedRuns());
        }
    }

    public function testRepeatedAssignmentReplacesTasks(): void
    {
        ($this->handler)(self::event([self::task('kept', 60), self::task('removed', 90, 2)]));

        ($this->handler)(self::event([self::task('kept', 120), self::task('added', 30, 2)], mentorId: 'new-mentor'));

        self::assertSame(['kept' => 120, 'added' => 30], $this->estimates());
        self::assertSame('new-mentor', $this->paths->find('student', 'path')?->mentorId());
        self::assertNotNull($this->progress->findForUpdate('student', 'added'));
    }

    public function testExistingProgressIsNotReset(): void
    {
        ($this->handler)(self::event([self::task('kept'), self::task('removed', 90, 2)]));
        $kept = $this->progress->findForUpdate('student', 'kept');
        $removed = $this->progress->findForUpdate('student', 'removed');
        self::assertInstanceOf(TaskProgress::class, $kept);
        self::assertInstanceOf(TaskProgress::class, $removed);
        $startedAt = new \DateTimeImmutable('2026-10-15T09:00:00Z');
        $kept->start($startedAt);
        $kept->recordFailedRun();
        $removed->complete($startedAt);

        ($this->handler)(self::event([self::task('kept')]));

        self::assertSame($kept, $this->progress->findForUpdate('student', 'kept'));
        self::assertSame(TaskStatus::InProgress, $kept->status());
        self::assertSame($startedAt, $kept->startedAt());
        self::assertSame(1, $kept->failedRuns());
        self::assertSame($removed, $this->progress->findForUpdate('student', 'removed'));
        self::assertSame(TaskStatus::Completed, $removed->status());
    }

    public function testProgressFromEarlierEventKeepsItsState(): void
    {
        $early = new TaskProgress('student', 't1', 'path');
        $early->start(new \DateTimeImmutable('2026-10-15T09:00:00Z'));
        $this->progress->add($early);

        ($this->handler)(self::event([self::task('t1')]));

        self::assertSame($early, $this->progress->findForUpdate('student', 't1'));
        self::assertSame(TaskStatus::InProgress, $early->status());
    }

    public function testPathWithoutTasks(): void
    {
        ($this->handler)(self::event([]));

        self::assertNotNull($this->paths->find('student', 'path'));
        self::assertSame([], $this->tasks->forPath('path'));
        self::assertSame([], $this->progress->progress);
    }
}
