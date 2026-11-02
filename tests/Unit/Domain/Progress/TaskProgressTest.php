<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Progress;

use App\Domain\Progress\TaskProgress;
use App\Domain\Progress\TaskStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TaskProgressTest extends TestCase
{
    private const EARLY = '2026-10-30T09:00:00Z';
    private const LATE = '2026-10-30T10:00:00Z';

    public function testNewProgressIsNotStarted(): void
    {
        $progress = new TaskProgress('student', 'task', 'path');

        self::assertSame('student', $progress->studentId());
        self::assertSame('task', $progress->taskId());
        self::assertSame('path', $progress->pathId());
        self::assertSame(TaskStatus::NotStarted, $progress->status());
        self::assertNull($progress->startedAt());
        self::assertNull($progress->completedAt());
        self::assertSame(0, $progress->activeMinutes());
        self::assertSame(0, $progress->failedRuns());
        self::assertSame(0, $progress->maxHintLevel());
    }

    /**
     * @param list<array{'start'|'complete', string}> $events
     */
    #[DataProvider('transitions')]
    public function testTransitions(array $events, TaskStatus $status, ?string $startedAt, ?string $completedAt): void
    {
        $progress = new TaskProgress('student', 'task', 'path');
        foreach ($events as [$event, $at]) {
            $progress->{$event}(new \DateTimeImmutable($at));
        }

        self::assertSame($status, $progress->status());
        self::assertEquals(null === $startedAt ? null : new \DateTimeImmutable($startedAt), $progress->startedAt());
        self::assertEquals(null === $completedAt ? null : new \DateTimeImmutable($completedAt), $progress->completedAt());
    }

    /**
     * @return iterable<string, array{list<array{'start'|'complete', string}>, TaskStatus, ?string, ?string}>
     */
    public static function transitions(): iterable
    {
        yield 'старт' => [[['start', self::EARLY]], TaskStatus::InProgress, self::EARLY, null];
        yield 'старт, затем завершение' => [[['start', self::EARLY], ['complete', self::LATE]], TaskStatus::Completed, self::EARLY, self::LATE];
        yield 'завершение раньше старта' => [[['complete', self::LATE], ['start', self::EARLY]], TaskStatus::Completed, self::EARLY, self::LATE];
        yield 'завершение без старта' => [[['complete', self::LATE]], TaskStatus::Completed, null, self::LATE];
        yield 'повторный старт позже' => [[['start', self::EARLY], ['start', self::LATE]], TaskStatus::InProgress, self::EARLY, null];
        yield 'повторный старт раньше' => [[['start', self::LATE], ['start', self::EARLY]], TaskStatus::InProgress, self::EARLY, null];
        yield 'повторное завершение позже' => [[['complete', self::EARLY], ['complete', self::LATE]], TaskStatus::Completed, null, self::EARLY];
        yield 'повторное завершение раньше' => [[['complete', self::LATE], ['complete', self::EARLY]], TaskStatus::Completed, null, self::EARLY];
    }

    #[DataProvider('statusesForFailedRun')]
    public function testFailedRunDoesNotChangeStatus(?string $event, TaskStatus $status): void
    {
        $progress = new TaskProgress('student', 'task', 'path');
        if (null !== $event) {
            $progress->{$event}(new \DateTimeImmutable(self::EARLY));
        }

        $progress->recordFailedRun();
        $progress->recordFailedRun();

        self::assertSame(2, $progress->failedRuns());
        self::assertSame($status, $progress->status());
    }

    /**
     * @return iterable<string, array{?string, TaskStatus}>
     */
    public static function statusesForFailedRun(): iterable
    {
        yield 'не начато' => [null, TaskStatus::NotStarted];
        yield 'в работе' => ['start', TaskStatus::InProgress];
        yield 'закрыто' => ['complete', TaskStatus::Completed];
    }
}
