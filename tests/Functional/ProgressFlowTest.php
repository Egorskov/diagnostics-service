<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Support\EventFlowTestCase;

/**
 * Как события меняют состояние ученика (progress-tracking) через настоящий воркер и БД.
 */
final class ProgressFlowTest extends EventFlowTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const PATH = '22222222-2222-4222-8222-222222222222';
    private const TASK = '33333333-3333-4333-8333-333333333333';
    private const MENTOR = '44444444-4444-4444-8444-444444444444';
    private const STAGE = '55555555-5555-4555-8555-555555555555';
    private const RUN = '66666666-6666-4666-8666-666666666666';

    private static function eventId(int $number): string
    {
        return \sprintf('0b6f6a7e-3c1d-4b8a-9e2f-%012d', $number);
    }

    private static function pathAssigned(int $number): string
    {
        return self::event(self::eventId($number), 'path.assigned', [
            'student_id' => self::STUDENT,
            'path_id' => self::PATH,
            'mentor_id' => self::MENTOR,
            'started_at' => '2026-10-01T00:00:00Z',
            'weekly_goal_minutes' => 1500,
            'tasks' => [['task_id' => self::TASK, 'stage_id' => self::STAGE, 'estimate_minutes' => 60, 'position' => 1, 'kind' => 'sandbox']],
        ]);
    }

    private static function sandboxRun(int $number): string
    {
        return self::event(self::eventId($number), 'sandbox.run', [
            'run_id' => self::RUN,
            'student_id' => self::STUDENT,
            'task_id' => self::TASK,
            'result' => 'failed',
            'error_type' => 'TypeError',
        ], '2026-10-30T12:10:00Z');
    }

    public function testFullEventFlow(): void
    {
        $task = ['student_id' => self::STUDENT, 'path_id' => self::PATH, 'task_id' => self::TASK];

        self::deliver('courses', self::pathAssigned(1));
        self::deliver('courses', self::event(self::eventId(2), 'task.started', $task, '2026-10-30T12:00:00Z'));
        self::deliver('courses', self::sandboxRun(3));
        self::deliver('courses', self::event(self::eventId(4), 'task.completed', $task, '2026-10-30T12:30:00Z'));
        self::deliver('time', self::event(self::eventId(5), 'time.logged', [
            'student_id' => self::STUDENT,
            'task_id' => self::TASK,
            'minutes' => 30,
            'category' => 'practice',
            'logged_for_date' => '2026-10-30',
        ]));
        // heartbeat по неизвестному заданию подтверждается и прогресс не создаёт
        self::deliver('heartbeat', self::event(self::eventId(6), 'activity.heartbeat', [
            'student_id' => self::STUDENT,
            'task_id' => self::RUN,
            'source' => 'theory',
        ]));

        self::consume(6, ['courses', 'time', 'heartbeat']);

        self::assertSame([], self::transport('failed')->getSent());
        self::assertSame(6, $this->rowCount('inbox_event'));

        self::assertSame([[
            'student_id' => self::STUDENT,
            'path_id' => self::PATH,
            'mentor_id' => self::MENTOR,
            'started_at' => '2026-10-01 00:00:00',
            'weekly_goal_minutes' => 1500,
        ]], $this->connection->fetchAllAssociative('SELECT student_id, path_id, mentor_id, started_at, weekly_goal_minutes FROM student_path'));

        self::assertSame([[
            'path_id' => self::PATH,
            'task_id' => self::TASK,
            'stage_id' => self::STAGE,
            'estimate_minutes' => 60,
            'position' => 1,
        ]], $this->connection->fetchAllAssociative('SELECT path_id, task_id, stage_id, estimate_minutes, position FROM path_task'));

        self::assertSame([[
            'student_id' => self::STUDENT,
            'task_id' => self::TASK,
            'path_id' => self::PATH,
            'status' => 'completed',
            'started_at' => '2026-10-30 12:00:00',
            'completed_at' => '2026-10-30 12:30:00',
            // сессии 12:00–12:10 (11 мин) и 12:30 (1 мин): между ними больше таймаута в 15 минут
            'active_minutes' => 12,
            'failed_runs' => 1,
            'max_hint_level' => 0,
        ]], $this->connection->fetchAllAssociative('SELECT student_id, task_id, path_id, status, started_at, completed_at, active_minutes, failed_runs, max_hint_level FROM task_progress'));

        self::assertSame([[
            'run_id' => self::RUN,
            'result' => 'failed',
            'error_type' => 'TypeError',
            'occurred_at' => '2026-10-30 12:10:00',
        ]], $this->connection->fetchAllAssociative('SELECT run_id, result, error_type, occurred_at FROM sandbox_run'));

        self::assertSame([[
            'student_id' => self::STUDENT,
            'task_id' => self::TASK,
            'category' => 'practice',
            'minutes' => 30,
            'logged_for_date' => '2026-10-30',
            'source_event_id' => self::eventId(5),
        ]], $this->connection->fetchAllAssociative('SELECT student_id, task_id, category, minutes, logged_for_date, source_event_id FROM time_log'));
    }

    public function testSandboxRunWithoutContextIsRetriedUntilPathArrives(): void
    {
        self::deliver('courses', self::sandboxRun(1));

        self::consume(1);

        // ничего не применилось, inbox откатился вместе с остальным, событие ждёт повтора
        self::assertSame(0, $this->rowCount('sandbox_run'));
        self::assertSame(0, $this->rowCount('inbox_event'));
        self::assertSame([], self::transport('failed')->getSent());
        self::assertCount(2, self::transport('courses')->getSent());

        // повтор отложен на 1 с, поэтому path.assigned обработается раньше него
        self::deliver('courses', self::pathAssigned(2));
        self::consume(2);

        self::assertSame(1, $this->rowCount('sandbox_run'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT failed_runs FROM task_progress'));
        self::assertSame(2, $this->rowCount('inbox_event'));
        self::assertSame([], self::transport('failed')->getSent());
    }
}
