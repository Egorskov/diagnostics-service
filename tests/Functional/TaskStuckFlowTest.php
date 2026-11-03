<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Signal\EscalateStuckTasks;
use App\Tests\Support\EventFlowTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * task.stuck через настоящий воркер: событие → прогресс → signal_state + outbox → relay → signals.
 */
final class TaskStuckFlowTest extends EventFlowTestCase
{
    use ClockSensitiveTrait;

    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const PATH = '22222222-2222-4222-8222-222222222222';
    private const TASK = '33333333-3333-4333-8333-333333333333';
    private const MENTOR = '44444444-4444-4444-8444-444444444444';
    private const STAGE = '55555555-5555-4555-8555-555555555555';

    private int $events = 0;

    protected function setUp(): void
    {
        parent::setUp();
        // сигнал ученику публикуется в этот момент, от него отсчитывается эскалация
        self::mockTime(new \DateTimeImmutable('2026-11-03T10:00:00Z'));
        self::deliver('courses', $this->courseEvent('path.assigned', [
            'student_id' => self::STUDENT,
            'path_id' => self::PATH,
            'mentor_id' => self::MENTOR,
            'started_at' => '2026-10-01T00:00:00Z',
            'weekly_goal_minutes' => 1500,
            'tasks' => [['task_id' => self::TASK, 'stage_id' => self::STAGE, 'estimate_minutes' => 60, 'position' => 1]],
        ]));
        self::deliver('courses', $this->courseEvent('task.started', ['student_id' => self::STUDENT, 'path_id' => self::PATH, 'task_id' => self::TASK], '2026-10-30T10:00:00Z'));
        self::consume(2);
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement("INSERT INTO threshold (key, value) VALUES ('stuck_failed_runs', '10') ON CONFLICT (key) DO NOTHING");
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function courseEvent(string $type, array $payload, string $occurredAt = '2026-10-30T09:00:00Z'): string
    {
        return self::event(\sprintf('0b6f6a7e-3c1d-4b8a-9e2f-%012d', ++$this->events), $type, $payload, $occurredAt);
    }

    /**
     * Точки раз в 15 минут с 10:15: сессия 10:00–$last.
     */
    private function heartbeatsUntil(string $last): void
    {
        $at = new \DateTimeImmutable('2026-10-30T10:15:00Z');
        $count = 0;
        while ($at <= new \DateTimeImmutable($last)) {
            self::deliver('heartbeat', $this->courseEvent('activity.heartbeat', ['student_id' => self::STUDENT, 'task_id' => self::TASK, 'source' => 'theory'], $at->format(\DATE_ATOM)));
            $at = $at->modify('+15 minutes');
            ++$count;
        }
        self::consume($count, ['heartbeat']);
    }

    private function failedRuns(int $count): void
    {
        for ($i = 1; $i <= $count; ++$i) {
            self::deliver('courses', $this->courseEvent('sandbox.run', [
                'run_id' => \sprintf('66666666-6666-4666-8666-%012d', $i),
                'student_id' => self::STUDENT,
                'task_id' => self::TASK,
                'result' => 'failed',
            ], '2026-10-30T10:05:00Z'));
        }
        self::consume($count);
    }

    public function testActiveTimeOverEstimatePublishesOneSignal(): void
    {
        // 10:00–11:30 = 91 минута при оценке 60 и пороге 1,5
        $this->heartbeatsUntil('2026-10-30T11:30:00Z');
        self::assertSame(91, (int) $this->connection->fetchOne('SELECT active_minutes FROM task_progress'));
        self::consume(1, ['outbox']);

        $signals = self::publishedSignals();
        self::assertCount(1, $signals);
        self::assertSame('diagnostics.task.stuck', $signals[0]->envelope()['event_type']);
        self::assertSame([
            'audience' => 'student',
            'student_id' => self::STUDENT,
            'mentor_id' => self::MENTOR,
            'task_id' => self::TASK,
            'reason' => 'time_exceeded',
            'active_minutes' => 91,
            'estimate_minutes' => 60,
            'failed_runs' => 0,
        ], $signals[0]->payload);
        self::assertSame([], self::transport('failed')->getSent());
    }

    public function testRepeatedHeartbeatsDoNotRepeatSignal(): void
    {
        $this->heartbeatsUntil('2026-10-30T11:30:00Z');
        self::deliver('heartbeat', $this->courseEvent('activity.heartbeat', ['student_id' => self::STUDENT, 'task_id' => self::TASK, 'source' => 'theory'], '2026-10-30T11:31:00Z'));
        self::consume(1, ['heartbeat']);

        self::assertSame(1, $this->outboxCount());
    }

    public function testExactlyOneAndHalfEstimatesIsNotStuck(): void
    {
        // 10:00–11:15 + точка в 11:29 = 90 минут
        $this->heartbeatsUntil('2026-10-30T11:15:00Z');
        self::deliver('heartbeat', $this->courseEvent('activity.heartbeat', ['student_id' => self::STUDENT, 'task_id' => self::TASK, 'source' => 'theory'], '2026-10-30T11:29:00Z'));
        self::consume(1, ['heartbeat']);

        self::assertSame(90, (int) $this->connection->fetchOne('SELECT active_minutes FROM task_progress'));
        self::assertSame(0, $this->outboxCount());
    }

    public function testTenthFailedRunPublishesFailedRunsReason(): void
    {
        $this->failedRuns(9);
        self::assertSame(0, $this->outboxCount());

        $this->failedRuns(10);
        self::consume(1, ['outbox']);

        $signals = self::publishedSignals();
        self::assertCount(1, $signals);
        self::assertSame('failed_runs', $signals[0]->payload['reason']);
        self::assertSame(10, $signals[0]->payload['failed_runs']);
    }

    public function testCompletionResetsState(): void
    {
        $this->heartbeatsUntil('2026-10-30T11:30:00Z');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT count(*) FROM signal_state WHERE active'));

        self::deliver('courses', $this->courseEvent('task.completed', ['student_id' => self::STUDENT, 'path_id' => self::PATH, 'task_id' => self::TASK], '2026-10-30T11:40:00Z'));
        self::consume(1);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM signal_state WHERE active'));
        self::assertSame(1, $this->outboxCount());
    }

    public function testMissingThresholdLeavesEventForRetry(): void
    {
        $this->connection->executeStatement("DELETE FROM threshold WHERE key = 'stuck_failed_runs'");
        $inbox = $this->rowCount('inbox_event');

        $this->failedRuns(1);

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT failed_runs FROM task_progress'));
        self::assertSame(0, $this->rowCount('sandbox_run'));
        self::assertSame($inbox, $this->rowCount('inbox_event'));
        self::assertSame(0, $this->rowCount('signal_state'));
        self::assertSame([], self::transport('failed')->getSent());
    }

    /**
     * @return list<string> аудитории сигналов, опубликованных эскалацией
     */
    private function escalateAt(string $now): array
    {
        self::mockTime(new \DateTimeImmutable($now));
        $before = \count(self::transport('signals')->getSent());
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new EscalateStuckTasks());
        self::consume(10, ['outbox']);

        return array_map(static fn ($signal) => $signal->payload['audience'], \array_slice(self::publishedSignals(), $before));
    }

    public function testEscalationAfterDayGoesToMentorOnce(): void
    {
        $this->heartbeatsUntil('2026-10-30T11:30:00Z');
        self::consume(1, ['outbox']);

        self::assertSame([], $this->escalateAt('2026-11-04T09:59:59Z'));
        self::assertSame(['mentor'], $this->escalateAt('2026-11-04T10:00:00Z'));
        self::assertSame([], $this->escalateAt('2026-11-04T11:00:00Z'));

        $mentorSignal = self::publishedSignals()[1];
        self::assertSame(self::MENTOR, $mentorSignal->payload['mentor_id']);
        self::assertSame('time_exceeded', $mentorSignal->payload['reason']);
        self::assertNull($mentorSignal->correlationId);
    }

    public function testNoEscalationAfterCompletion(): void
    {
        $this->heartbeatsUntil('2026-10-30T11:30:00Z');
        self::deliver('courses', $this->courseEvent('task.completed', ['student_id' => self::STUDENT, 'path_id' => self::PATH, 'task_id' => self::TASK], '2026-10-30T11:40:00Z'));
        self::consume(1);
        self::consume(1, ['outbox']);

        self::assertSame([], $this->escalateAt('2026-11-04T10:00:00Z'));
    }
}
