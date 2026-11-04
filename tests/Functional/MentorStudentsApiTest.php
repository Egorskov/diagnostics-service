<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\ApiToken;
use App\Tests\Support\EventFlowTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * События → воркер → GET /api/v1/mentor/students.
 */
final class MentorStudentsApiTest extends EventFlowTestCase
{
    use ClockSensitiveTrait;

    private const MENTOR = '44444444-4444-4444-8444-444444444444';
    private const OTHER_MENTOR = '44444444-4444-4444-8444-555555555555';
    private const AHEAD = '11111111-1111-4111-8111-000000000001';
    private const BEHIND = '11111111-1111-4111-8111-000000000002';
    private const MOVED = '11111111-1111-4111-8111-000000000003';
    private const STAGE = '55555555-5555-4555-8555-555555555555';
    /** С 2026-10-01 прошло 4,96 недели: при цели 1000 план — 4959 минут */
    private const NOW = '2026-11-04T17:12:00Z';

    private int $events = 0;

    /**
     * @param array<string, mixed> $payload
     */
    private function send(string $type, array $payload, string $occurredAt): void
    {
        self::deliver('courses', self::event(\sprintf('66666666-6666-4666-8666-%012d', ++$this->events), $type, $payload, $occurredAt));
    }

    /**
     * Путь из двух заданий: первое на $done минут закрыто 2026-11-01, второе на $open открыто.
     */
    private function assign(string $student, string $mentor, string $startedAt, int $done, int $open): void
    {
        $path = substr_replace($student, '2222', 0, 4);
        $doneTask = substr_replace($student, '3333', 0, 4);
        $this->send('path.assigned', [
            'student_id' => $student,
            'path_id' => $path,
            'mentor_id' => $mentor,
            'started_at' => $startedAt,
            'weekly_goal_minutes' => 1000,
            'tasks' => [
                ['task_id' => $doneTask, 'stage_id' => self::STAGE, 'estimate_minutes' => $done, 'position' => 1, 'kind' => 'sandbox'],
                ['task_id' => substr_replace($student, '4444', 0, 4), 'stage_id' => self::STAGE, 'estimate_minutes' => $open, 'position' => 2, 'kind' => 'theory'],
            ],
        ], $startedAt);
        $this->send('task.completed', ['student_id' => $student, 'path_id' => $path, 'task_id' => $doneTask], '2026-11-01T10:00:00Z');
    }

    private function seed(): void
    {
        $this->assign(self::AHEAD, self::MENTOR, '2026-10-01T00:00:00Z', 5500, 1000);
        $this->assign(self::BEHIND, self::MENTOR, '2026-10-01T00:00:00Z', 3000, 3000);
        // был учеником MENTOR, теперь текущий путь у другого ментора
        $this->assign(self::MOVED, self::MENTOR, '2026-09-01T00:00:00Z', 600, 600);
        $this->send('path.assigned', [
            'student_id' => self::MOVED,
            'path_id' => '22222222-2222-4222-8222-999999999999',
            'mentor_id' => self::OTHER_MENTOR,
            'started_at' => '2026-10-15T00:00:00Z',
            'weekly_goal_minutes' => 1000,
            'tasks' => [['task_id' => '33333333-3333-4333-8333-999999999999', 'stage_id' => self::STAGE, 'estimate_minutes' => 600, 'position' => 1, 'kind' => 'theory']],
        ], '2026-10-15T00:00:00Z');
        self::deliver('heartbeat', self::event('66666666-6666-4666-8666-999999999999', 'activity.heartbeat', ['student_id' => self::BEHIND, 'source' => 'theory'], '2026-11-03T09:30:00Z'));

        self::consume($this->events + 1, ['courses', 'heartbeat']);
        self::assertSame([], self::transport('failed')->getSent());
        $this->connection->insert('signal_state', ['id' => 'diagnostics.student.inactive|student|'.self::AHEAD.'|', 'signal_type' => 'diagnostics.student.inactive', 'audience' => 'student', 'student_id' => self::AHEAD, 'subject_id' => null, 'active' => 'true']);

        self::mockTime(new \DateTimeImmutable(self::NOW));
    }

    public function testStudentsSortedByRisk(): void
    {
        $this->seed();

        $response = self::get('/api/v1/mentor/students', ApiToken::mentor(self::MENTOR));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['students' => [
            [
                'student_id' => self::BEHIND,
                'path_id' => '22221111-1111-4111-8111-000000000002',
                'spi' => 0.6,
                'cpi' => null,
                // план 2026-10-01 + 42 дня = 2026-11-12, прогноз — сегодня + 28 дней
                'delay_days' => 20,
                'forecast_finish' => '2026-12-02',
                'last_activity_at' => '2026-11-03T09:30:00Z',
                'active_signals' => [],
            ],
            [
                'student_id' => self::AHEAD,
                'path_id' => '22221111-1111-4111-8111-000000000001',
                'spi' => 1.11,
                'cpi' => null,
                // план 2026-10-01 + 46 дней = 2026-11-16, прогноз — сегодня + 6 дней
                'delay_days' => -6,
                'forecast_finish' => '2026-11-10',
                // task.completed — тоже точка активности (SPEC §3)
                'last_activity_at' => '2026-11-01T10:00:00Z',
                'active_signals' => ['student.inactive'],
            ],
        ]], self::json($response));
    }

    public function testOtherMentorSeesMovedStudent(): void
    {
        $this->seed();

        $students = self::json(self::get('/api/v1/mentor/students', ApiToken::mentor(self::OTHER_MENTOR)))['students'];

        self::assertIsArray($students);
        self::assertSame([self::MOVED], array_column($students, 'student_id'));
    }

    public function testNoStudents(): void
    {
        $response = self::get('/api/v1/mentor/students', ApiToken::mentor(self::MENTOR));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('{"students":[]}', $response->getContent());
    }

    public function testStudentIsForbiddenAndAnonymousUnauthorized(): void
    {
        $student = self::get('/api/v1/mentor/students', ApiToken::student(self::AHEAD));
        $anonymous = self::get('/api/v1/mentor/students', null);

        self::assertSame([403, ['error' => 'forbidden']], [$student->getStatusCode(), self::json($student)]);
        self::assertSame([401, ['error' => 'unauthorized']], [$anonymous->getStatusCode(), self::json($anonymous)]);
    }
}
