<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiToken;
use App\Tests\Support\EventFlowTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Пример SPEC §8 целиком: события → воркер → GET /api/v1/students/{id}/summary.
 */
final class StudentSummaryApiTest extends EventFlowTestCase
{
    use ClockSensitiveTrait;

    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const STRANGER = '11111111-1111-4111-8111-222222222222';
    private const PATH = '22222222-2222-4222-8222-222222222222';
    private const MENTOR = '44444444-4444-4444-8444-444444444444';
    private const OTHER_MENTOR = '44444444-4444-4444-8444-555555555555';
    private const TASK_DONE = '33333333-3333-4333-8333-000000000001';
    private const TASK_OPEN = '33333333-3333-4333-8333-000000000002';
    private const STAGE = '55555555-5555-4555-8555-555555555555';
    /** Среда 18:12 по Белграду; неделя — 2026-11-02 … 2026-11-08 */
    private const NOW = '2026-11-04T17:12:00Z';

    private int $events = 0;

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $transport, string $type, array $payload, string $occurredAt): void
    {
        self::deliver($transport, self::event(\sprintf('66666666-6666-4666-8666-%012d', ++$this->events), $type, $payload, $occurredAt));
    }

    private function logTime(?string $task, string $category, int $minutes, string $date): void
    {
        $this->send('time', 'time.logged', ['student_id' => self::STUDENT, 'task_id' => $task, 'minutes' => $minutes, 'category' => $category, 'logged_for_date' => $date], $date.'T20:00:00Z');
    }

    /**
     * Heartbeat каждые 15 минут и последний в $to: одна сессия длиной $to − $from + 1 мин.
     */
    private function activity(string $from, string $to): void
    {
        $last = new \DateTimeImmutable($to);
        for ($at = new \DateTimeImmutable($from); $at < $last; $at = $at->modify('+15 minutes')) {
            $this->send('heartbeat', 'activity.heartbeat', ['student_id' => self::STUDENT, 'source' => 'theory'], $at->format('Y-m-d\TH:i:s\Z'));
        }
        $this->send('heartbeat', 'activity.heartbeat', ['student_id' => self::STUDENT, 'source' => 'theory'], $to);
    }

    private function seedSpecExample(): void
    {
        $this->send('courses', 'path.assigned', [
            'student_id' => self::STUDENT,
            'path_id' => self::PATH,
            'mentor_id' => self::MENTOR,
            'started_at' => '2026-10-18T22:00:00Z',
            'weekly_goal_minutes' => 1500,
            'tasks' => [
                ['task_id' => self::TASK_DONE, 'stage_id' => self::STAGE, 'estimate_minutes' => 2700, 'position' => 1, 'kind' => 'sandbox'],
                ['task_id' => self::TASK_OPEN, 'stage_id' => self::STAGE, 'estimate_minutes' => 12300, 'position' => 2, 'kind' => 'theory'],
            ],
        ], '2026-10-18T22:00:00Z');
        $this->send('courses', 'task.completed', ['student_id' => self::STUDENT, 'path_id' => self::PATH, 'task_id' => self::TASK_DONE], '2026-10-30T10:00:00Z');
        // AC — 3300 по заданиям пути; неделя — 540 без задания
        $this->logTime(self::TASK_DONE, 'practice', 2000, '2026-10-20');
        $this->logTime(self::TASK_OPEN, 'practice', 1300, '2026-10-25');
        $this->logTime(null, 'practice', 450, '2026-11-02');
        $this->logTime(null, 'interview', 90, '2026-11-04');
        // воскресенье — серия, но не неделя; затем 200 + 200 + 210 минут
        $this->activity('2026-11-01T10:00:00Z', '2026-11-01T10:09:00Z');
        $this->activity('2026-11-02T10:00:00Z', '2026-11-02T13:19:00Z');
        $this->activity('2026-11-03T10:00:00Z', '2026-11-03T13:19:00Z');
        $this->activity('2026-11-04T10:00:00Z', '2026-11-04T13:29:00Z');

        self::consume($this->events, ['courses', 'time', 'heartbeat']);
        self::assertSame([], self::transport('failed')->getSent());

        $this->signal('diagnostics.task.stuck', 'student', self::TASK_OPEN, true);
        $this->signal('diagnostics.weekly_goal.behind', 'student', '2026-10-26', true);
        $this->signal('diagnostics.student.inactive', 'student', null, false);

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    private function signal(string $type, string $audience, ?string $subject, bool $active): void
    {
        $this->connection->insert('signal_state', ['id' => implode('|', [$type, $audience, self::STUDENT, $subject ?? '']), 'signal_type' => $type, 'audience' => $audience, 'student_id' => self::STUDENT, 'subject_id' => $subject, 'active' => $active ? 'true' : 'false']);
    }

    public function testWholeSpecExample(): void
    {
        $this->seedSpecExample();

        $response = self::get('/api/v1/students/'.self::STUDENT.'/summary', ApiToken::student(self::STUDENT));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame([
            'student_id' => self::STUDENT,
            'path_id' => self::PATH,
            'ev_minutes' => 2700,
            'pv_minutes' => 3600,
            'ac_minutes' => 3300,
            'spi' => 0.75,
            'cpi' => 0.82,
            'forecast_finish' => '2027-01-20',
            'planned_finish' => '2026-12-28',
            'delay_days' => 23,
            'week' => ['goal_minutes' => 1500, 'logged_minutes' => 540, 'active_minutes' => 610, 'active_days' => 3],
            'streak_days' => 4,
            'balance' => ['theory' => 0.0, 'practice' => 0.83, 'interview' => 0.17],
            'active_signals' => ['task.stuck'],
        ], self::json($response));
        self::assertStringContainsString('"theory":0.0', (string) $response->getContent());
    }

    public function testMentorOfCurrentPathSeesSummary(): void
    {
        $this->seedSpecExample();

        $response = self::get('/api/v1/students/'.self::STUDENT.'/summary', ApiToken::mentor(self::MENTOR));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::PATH, self::json($response)['path_id']);
    }

    /**
     * @param array<string, mixed>|null $claims
     */
    #[DataProvider('denied')]
    public function testAccessDenied(string $studentId, ?array $claims, int $status, string $error): void
    {
        $this->seedSpecExample();

        $response = self::get('/api/v1/students/'.$studentId.'/summary', null === $claims ? null : ApiToken::withClaims($claims));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame(['error' => $error], self::json($response));
    }

    /**
     * @return iterable<string, array{string, ?array<string, mixed>, int, string}>
     */
    public static function denied(): iterable
    {
        yield 'нет токена' => [self::STUDENT, null, 401, 'unauthorized'];
        yield 'неизвестная роль' => [self::STUDENT, ['sub' => self::STUDENT, 'role' => 'admin'], 401, 'unauthorized'];
        yield 'чужой ученик' => [self::STUDENT, ['sub' => self::STRANGER, 'role' => 'student'], 403, 'forbidden'];
        yield 'чужой ментор' => [self::STUDENT, ['sub' => self::OTHER_MENTOR, 'role' => 'mentor'], 403, 'forbidden'];
        yield 'ментор — ученика без пути' => [self::STRANGER, ['sub' => self::MENTOR, 'role' => 'mentor'], 403, 'forbidden'];
        yield 'ментор — не UUID' => ['not-a-uuid', ['sub' => self::MENTOR, 'role' => 'mentor'], 403, 'forbidden'];
        yield 'ученик без пути' => [self::STRANGER, ['sub' => self::STRANGER, 'role' => 'student'], 404, 'not_found'];
    }
}
