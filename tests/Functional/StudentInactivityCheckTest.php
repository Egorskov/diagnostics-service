<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Application\Signal\CheckStudentInactivity;
use App\Tests\Support\EventFlowTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Проверка расписания через шину (doctrine_transaction) и outbox → relay → signals.
 */
final class StudentInactivityCheckTest extends EventFlowTestCase
{
    use ClockSensitiveTrait;

    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const MENTOR = '44444444-4444-4444-8444-444444444444';

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
        $this->activity('2026-11-01 10:00:00');
    }

    private function activity(string $lastSeenAt): void
    {
        $this->connection->insert('activity_session', ['student_id' => self::STUDENT, 'task_id' => null, 'source' => 'theory', 'started_at' => $lastSeenAt, 'last_seen_at' => $lastSeenAt]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function checkAt(string $now): array
    {
        self::mockTime(new \DateTimeImmutable($now));
        $before = \count(self::transport('signals')->getSent());
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new CheckStudentInactivity());
        self::consume(10, ['outbox']);

        return array_map(static fn ($signal) => $signal->payload, \array_slice(self::publishedSignals(), $before));
    }

    public function testThreeDaysNotifiesStudentOnly(): void
    {
        self::assertSame([], $this->checkAt('2026-11-04T09:59:00Z'));

        self::assertSame([[
            'audience' => 'student',
            'student_id' => self::STUDENT,
            'mentor_id' => self::MENTOR,
            'days_inactive' => 3,
            'last_activity_at' => '2026-11-01T10:00:00+00:00',
        ]], $this->checkAt('2026-11-04T10:00:00Z'));
    }

    public function testSevenDaysNotifiesMentorWithoutRepeatingStudent(): void
    {
        $this->checkAt('2026-11-04T10:00:00Z');

        $payloads = $this->checkAt('2026-11-08T10:00:00Z');

        self::assertSame(['mentor'], array_column($payloads, 'audience'));
        self::assertSame(7, $payloads[0]['days_inactive']);
        self::assertSame([], $this->checkAt('2026-11-08T11:00:00Z'));
    }

    public function testReturnResetsBothAudiences(): void
    {
        $this->checkAt('2026-11-08T10:00:00Z');
        $this->activity('2026-11-08T10:30:00');

        self::assertSame([], $this->checkAt('2026-11-08T11:00:00Z'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT count(*) FROM signal_state WHERE active'));

        // снова пропал — новый переход, новый сигнал
        self::assertSame(['student'], array_column($this->checkAt('2026-11-11T10:30:00Z'), 'audience'));
    }

    public function testStudentWithoutSessionsCountsFromPathStart(): void
    {
        $this->connection->executeStatement('DELETE FROM activity_session');

        $payloads = $this->checkAt('2026-10-04T00:00:00Z');

        self::assertSame('2026-10-01T00:00:00+00:00', $payloads[0]['last_activity_at']);
    }
}
