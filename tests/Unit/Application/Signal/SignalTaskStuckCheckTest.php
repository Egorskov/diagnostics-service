<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Signal;

use App\Application\Event\EventMetadata;
use App\Application\Port\InvalidThreshold;
use App\Application\Signal\SignalEmitter;
use App\Application\Signal\SignalTaskStuckCheck;
use App\Domain\Progress\PathTask;
use App\Domain\Progress\StudentPath;
use App\Domain\Progress\TaskProgress;
use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalType;
use App\Tests\Support\FixedThresholds;
use App\Tests\Support\InMemoryPathTaskRepository;
use App\Tests\Support\InMemorySignalStateRepository;
use App\Tests\Support\InMemoryStudentPathRepository;
use App\Tests\Support\InMemoryTaskProgressRepository;
use App\Tests\Support\RecordingSignalOutbox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class SignalTaskStuckCheckTest extends TestCase
{
    private InMemoryTaskProgressRepository $progress;
    private InMemoryPathTaskRepository $pathTasks;
    private InMemorySignalStateRepository $states;
    private RecordingSignalOutbox $outbox;
    private TaskProgress $task;

    protected function setUp(): void
    {
        $this->progress = new InMemoryTaskProgressRepository();
        $this->pathTasks = new InMemoryPathTaskRepository();
        $this->states = new InMemorySignalStateRepository();
        $this->outbox = new RecordingSignalOutbox();

        $this->task = new TaskProgress('student', 'task', 'path');
        $this->task->start(new \DateTimeImmutable('2026-11-03T09:00:00Z'));
        $this->progress->add($this->task);
        $this->pathTasks->add(new PathTask('path', 'task', 'stage', 60, 1));
    }

    private function stuckCheck(?FixedThresholds $thresholds = null): SignalTaskStuckCheck
    {
        $paths = new InMemoryStudentPathRepository();
        $paths->add(new StudentPath('student', 'path', 'mentor', new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500));

        return new SignalTaskStuckCheck(
            $this->progress,
            $this->pathTasks,
            $paths,
            $thresholds ?? new FixedThresholds(),
            new SignalEmitter($this->states, $this->outbox),
            new MockClock('2026-11-03T12:00:00Z'),
        );
    }

    private function check(?FixedThresholds $thresholds = null): void
    {
        $this->stuckCheck($thresholds)->check('student', 'task', new EventMetadata('event', 'activity.heartbeat', 1, new \DateTimeImmutable('2026-11-03T11:59:00Z'), 'courses'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function payloads(): array
    {
        return array_map(static fn ($signal) => $signal->payload, $this->outbox->signals);
    }

    private function state(Audience $audience): bool
    {
        return $this->states->find(new SignalKey(SignalType::TaskStuck, $audience, 'student', 'task'))?->isActive() ?? false;
    }

    public function testTimeExceededPublishesOnce(): void
    {
        $this->task->updateActiveMinutes(91);

        $this->check();
        $this->check();

        self::assertSame([[
            'audience' => 'student',
            'student_id' => 'student',
            'mentor_id' => 'mentor',
            'task_id' => 'task',
            'reason' => 'time_exceeded',
            'active_minutes' => 91,
            'estimate_minutes' => 60,
            'failed_runs' => 0,
        ]], $this->payloads());
        self::assertSame('event', $this->outbox->signals[0]->correlationId);
        self::assertSame(SignalType::TaskStuck, $this->outbox->signals[0]->type);
    }

    public function testNotStuckPublishesNothing(): void
    {
        $this->task->updateActiveMinutes(90);

        $this->check();

        self::assertSame([], $this->outbox->signals);
        self::assertSame([], $this->states->states);
    }

    public function testFailedRunsReason(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->task->recordFailedRun();
        }

        $this->check();

        self::assertSame('failed_runs', $this->payloads()[0]['reason']);
        self::assertSame(10, $this->payloads()[0]['failed_runs']);
    }

    public function testTaskOutsidePathChecksOnlyFailedRuns(): void
    {
        $this->pathTasks->remove(new PathTask('path', 'task', 'stage', 60, 1));
        $this->task->updateActiveMinutes(5000);
        $this->check();
        self::assertSame([], $this->outbox->signals);

        for ($i = 0; $i < 10; ++$i) {
            $this->task->recordFailedRun();
        }
        $this->check();

        self::assertSame('failed_runs', $this->payloads()[0]['reason']);
        self::assertArrayNotHasKey('estimate_minutes', $this->payloads()[0]);
    }

    public function testCompletionResetsBothAudiences(): void
    {
        $this->task->updateActiveMinutes(91);
        $this->check();
        $this->stuckCheck()->escalate('student', 'task');
        self::assertTrue($this->state(Audience::Mentor));

        $this->task->complete(new \DateTimeImmutable('2026-11-03T12:00:00Z'));
        // закрытому заданию пороги не нужны
        $this->check(new FixedThresholds(stuckTimeRatio: null, stuckFailedRuns: null));

        self::assertFalse($this->state(Audience::Student));
        self::assertFalse($this->state(Audience::Mentor));
        self::assertCount(2, $this->outbox->signals);
    }

    public function testMissingThresholdFails(): void
    {
        $this->expectException(InvalidThreshold::class);

        $this->check(new FixedThresholds(stuckFailedRuns: null));
    }

    public function testEscalationPublishesToMentorOnce(): void
    {
        $this->task->updateActiveMinutes(91);
        $this->check();

        $this->stuckCheck()->escalate('student', 'task');
        $this->stuckCheck()->escalate('student', 'task');

        self::assertSame(['student', 'mentor'], array_column($this->payloads(), 'audience'));
        self::assertNull($this->outbox->signals[1]->correlationId);
        self::assertTrue($this->state(Audience::Student));
    }

    public function testEscalationWithoutStuckResetsStudent(): void
    {
        $this->task->updateActiveMinutes(91);
        $this->check();
        // оценку подняли, а события с тех пор не было
        $this->pathTasks->add(new PathTask('path', 'task', 'stage', 120, 1));

        $this->stuckCheck()->escalate('student', 'task');

        self::assertCount(1, $this->outbox->signals);
        self::assertFalse($this->state(Audience::Student));
    }
}
