<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Signal\CheckWeeklyGoals;
use App\Tests\Support\EventFlowTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;

final class WeeklyGoalCheckTest extends EventFlowTestCase
{
    use ClockSensitiveTrait;

    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const MENTOR = '44444444-4444-4444-8444-444444444444';
    // четверг 2026-11-05 18:00 по Белграду (CET)
    private const THURSDAY_EVENING = '2026-11-05T17:00:00Z';

    private int $logs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection->insert('student_path', [
            'student_id' => self::STUDENT,
            'path_id' => '22222222-2222-4222-8222-222222222222',
            'mentor_id' => self::MENTOR,
            'started_at' => '2026-10-01 00:00:00',
            'weekly_goal_minutes' => 1500,
        ]);
    }

    private function log(int $minutes, string $date, ?string $task = null): void
    {
        $this->connection->insert('time_log', [
            'student_id' => self::STUDENT,
            'task_id' => $task,
            'category' => 'practice',
            'minutes' => $minutes,
            'logged_for_date' => $date,
            'source_event_id' => \sprintf('77777777-7777-4777-8777-%012d', ++$this->logs),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checkAt(string $now): array
    {
        self::mockTime(new \DateTimeImmutable($now));
        $before = \count(self::transport('signals')->getSent());
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CheckWeeklyGoals());
        self::consume(10, ['outbox']);

        return array_map(static fn ($signal) => $signal->payload, \array_slice(self::publishedSignals(), $before));
    }

    public function testBehindOnThursdayEvening(): void
    {
        $this->log(500, '2026-11-02');
        $this->log(40, '2026-11-04', '33333333-3333-4333-8333-333333333333');
        // прошлая неделя не считается
        $this->log(1000, '2026-11-01');

        self::assertSame([], $this->checkAt('2026-11-05T16:00:00Z'));
        self::assertSame([[
            'audience' => 'student',
            'student_id' => self::STUDENT,
            'mentor_id' => self::MENTOR,
            'goal_minutes' => 1500,
            'logged_minutes' => 540,
            'week_start' => '2026-11-02',
        ]], $this->checkAt(self::THURSDAY_EVENING));
        self::assertSame([], $this->checkAt('2026-11-05T18:00:00Z'));
    }

    public function testHalfOfGoalIsEnough(): void
    {
        $this->log(750, '2026-11-03');

        self::assertSame([], $this->checkAt(self::THURSDAY_EVENING));
    }

    public function testNextWeekGivesNewSignal(): void
    {
        $this->checkAt(self::THURSDAY_EVENING);

        $payloads = $this->checkAt('2026-11-12T17:00:00Z');

        self::assertSame(['2026-11-09'], array_column($payloads, 'week_start'));
        self::assertSame(2, $this->rowCount('signal_state'));
    }

    public function testZeroGoalIsNotChecked(): void
    {
        $this->connection->update('student_path', ['weekly_goal_minutes' => 0], ['student_id' => self::STUDENT]);

        self::assertSame([], $this->checkAt(self::THURSDAY_EVENING));
        self::assertSame(0, $this->rowCount('signal_state'));
    }
}
