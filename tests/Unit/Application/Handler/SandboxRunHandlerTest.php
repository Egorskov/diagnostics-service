<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Handler;

use App\Application\Event\EventMetadata;
use App\Application\Event\Incoming\SandboxRun as SandboxRunEvent;
use App\Application\Handler\SandboxRunHandler;
use App\Application\Handler\UnknownTaskProgress;
use App\Domain\Progress\ActivitySource;
use App\Domain\Progress\SandboxResult;
use App\Domain\Progress\TaskProgress;
use App\Domain\Progress\TaskStatus;
use App\Tests\Support\InMemorySandboxRunRepository;
use App\Tests\Support\InMemoryTaskProgressRepository;
use App\Tests\Support\RecordingActivityTracker;
use App\Tests\Support\RecordingTaskStuckCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SandboxRunHandlerTest extends TestCase
{
    private const AT = '2026-10-30T09:00:00Z';

    private InMemorySandboxRunRepository $runs;
    private InMemoryTaskProgressRepository $progress;
    private RecordingActivityTracker $activity;
    private RecordingTaskStuckCheck $stuck;
    private SandboxRunHandler $handler;

    protected function setUp(): void
    {
        $this->runs = new InMemorySandboxRunRepository();
        $this->progress = new InMemoryTaskProgressRepository();
        $this->activity = new RecordingActivityTracker();
        $this->stuck = new RecordingTaskStuckCheck();
        $this->handler = new SandboxRunHandler($this->runs, $this->progress, $this->activity, $this->stuck);
    }

    private static function event(SandboxResult $result, string $runId = 'run', ?string $errorType = null): SandboxRunEvent
    {
        $metadata = new EventMetadata('event', 'sandbox.run', 1, new \DateTimeImmutable(self::AT), 'courses');

        return new SandboxRunEvent($metadata, $runId, 'student', 'task', $result, errorType: $errorType);
    }

    private function assignedProgress(): TaskProgress
    {
        $progress = new TaskProgress('student', 'task', 'path');
        $this->progress->add($progress);

        return $progress;
    }

    #[DataProvider('results')]
    public function testRunIsSavedAndCounted(SandboxResult $result, int $failedRuns): void
    {
        $progress = $this->assignedProgress();

        ($this->handler)(self::event($result, errorType: 'TypeError'));

        $run = $this->runs->find('run');
        self::assertNotNull($run);
        self::assertSame($result, $run->result());
        self::assertSame('TypeError', $run->errorType());
        self::assertEquals(new \DateTimeImmutable(self::AT), $run->occurredAt());
        self::assertSame($failedRuns, $progress->failedRuns());
        self::assertSame(TaskStatus::NotStarted, $progress->status());
    }

    /**
     * @return iterable<string, array{SandboxResult, int}>
     */
    public static function results(): iterable
    {
        yield 'failed' => [SandboxResult::Failed, 1];
        yield 'error' => [SandboxResult::Error, 1];
        yield 'passed' => [SandboxResult::Passed, 0];
    }

    public function testRunIsActivityPoint(): void
    {
        $this->assignedProgress();

        ($this->handler)(self::event(SandboxResult::Passed));

        self::assertCount(1, $this->activity->points);
        self::assertSame('task', $this->activity->points[0]->taskId);
        self::assertSame(ActivitySource::Sandbox, $this->activity->points[0]->source);
        self::assertEquals(new \DateTimeImmutable(self::AT), $this->activity->points[0]->occurredAt);
        self::assertSame([['student', 'task', 'event']], $this->stuck->checks);
    }

    public function testSameRunWithNewEventChangesNothing(): void
    {
        $progress = $this->assignedProgress();
        ($this->handler)(self::event(SandboxResult::Failed));

        ($this->handler)(self::event(SandboxResult::Failed));

        self::assertCount(1, $this->runs->runs);
        self::assertSame(1, $progress->failedRuns());
        self::assertCount(1, $this->activity->points);
        self::assertCount(1, $this->stuck->checks);
    }

    public function testUnknownTaskFailsAndSavesNothing(): void
    {
        try {
            ($this->handler)(self::event(SandboxResult::Failed));
            self::fail('Ожидалось исключение UnknownTaskProgress');
        } catch (UnknownTaskProgress $exception) {
            self::assertStringContainsString('student', $exception->getMessage());
        }

        self::assertSame([], $this->runs->runs);
        self::assertSame([], $this->activity->points);
        self::assertSame([], $this->stuck->checks);
    }
}
