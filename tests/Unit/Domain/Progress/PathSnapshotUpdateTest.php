<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Progress;

use App\Domain\Progress\PathTask;
use App\Domain\Progress\StudentPath;
use PHPUnit\Framework\TestCase;

final class PathSnapshotUpdateTest extends TestCase
{
    public function testStudentPathTakesValuesFromSnapshot(): void
    {
        $path = new StudentPath('student', 'path', 'mentor-1', new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500);
        $startedAt = new \DateTimeImmutable('2026-11-01T00:00:00Z');

        $path->updateFromSnapshot('mentor-2', $startedAt, 900);

        self::assertSame('student', $path->studentId());
        self::assertSame('path', $path->pathId());
        self::assertSame('mentor-2', $path->mentorId());
        self::assertSame($startedAt, $path->startedAt());
        self::assertSame(900, $path->weeklyGoalMinutes());
    }

    public function testPathTaskTakesValuesFromSnapshot(): void
    {
        $task = new PathTask('path', 'task', 'stage-1', 60, 1);

        $task->updateFromSnapshot('stage-2', 90, 5);

        self::assertSame('path', $task->pathId());
        self::assertSame('task', $task->taskId());
        self::assertSame('stage-2', $task->stageId());
        self::assertSame(90, $task->estimateMinutes());
        self::assertSame(5, $task->position());
    }
}
