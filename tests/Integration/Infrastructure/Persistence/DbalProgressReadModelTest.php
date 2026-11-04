<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Application\Port\CurrentPath;
use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\PathPlan;
use App\Domain\Metrics\PlannedTask;
use App\Domain\Signal\SignalType;
use App\Infrastructure\Persistence\Doctrine\DbalProgressReadModel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalProgressReadModelTest extends KernelTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const OTHER = '22222222-2222-4222-8222-222222222222';
    private const OLD_PATH = 'aaaaaaaa-0000-4000-8000-000000000001';
    private const PATH = 'aaaaaaaa-0000-4000-8000-000000000002';
    private const OTHER_PATH = 'aaaaaaaa-0000-4000-8000-000000000003';
    private const OLD_MENTOR = 'bbbbbbbb-0000-4000-8000-00000000000a';
    private const MENTOR = 'bbbbbbbb-0000-4000-8000-00000000000b';
    private const TASK_DONE = 'cccccccc-0000-4000-8000-000000000001';
    private const TASK_OPEN = 'cccccccc-0000-4000-8000-000000000002';
    private const TASK_ELSEWHERE = 'cccccccc-0000-4000-8000-000000000003';

    private Connection $connection;
    private DbalProgressReadModel $readModel;
    private int $logs = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        foreach (['student_path', 'path_task', 'task_progress', 'activity_session', 'time_log', 'signal_state'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->readModel = new DbalProgressReadModel($this->connection);
    }

    private function path(string $student, string $path, string $mentor, string $startedAt, int $goal = 1500): void
    {
        $this->connection->insert('student_path', ['student_id' => $student, 'path_id' => $path, 'mentor_id' => $mentor, 'started_at' => $startedAt, 'weekly_goal_minutes' => $goal]);
    }

    private function task(string $task, int $estimate, int $position): void
    {
        $this->connection->insert('path_task', ['path_id' => self::PATH, 'task_id' => $task, 'stage_id' => 'dddddddd-0000-4000-8000-000000000001', 'estimate_minutes' => $estimate, 'position' => $position]);
    }

    private function progress(string $task, string $status, ?string $completedAt): void
    {
        $this->connection->insert('task_progress', ['student_id' => self::STUDENT, 'task_id' => $task, 'path_id' => self::PATH, 'status' => $status, 'started_at' => '2026-10-02 10:00:00', 'completed_at' => $completedAt, 'active_minutes' => 0, 'failed_runs' => 0, 'max_hint_level' => 0]);
    }

    private function log(string $student, ?string $task, string $category, int $minutes, string $date): void
    {
        $this->connection->insert('time_log', ['student_id' => $student, 'task_id' => $task, 'category' => $category, 'minutes' => $minutes, 'logged_for_date' => $date, 'source_event_id' => \sprintf('77777777-7777-4777-8777-%012d', ++$this->logs)]);
    }

    private function signal(string $type, string $audience, ?string $subject, bool $active, string $student = self::STUDENT): void
    {
        $this->connection->insert('signal_state', ['id' => implode('|', [$type, $audience, $student, $subject ?? '']), 'signal_type' => $type, 'audience' => $audience, 'student_id' => $student, 'subject_id' => $subject, 'active' => $active ? 'true' : 'false']);
    }

    public function testCurrentPathIsLatestStart(): void
    {
        $this->path(self::STUDENT, self::OLD_PATH, self::OLD_MENTOR, '2026-09-01 00:00:00', 1000);
        $this->path(self::STUDENT, self::PATH, self::MENTOR, '2026-10-01 00:00:00');

        self::assertEquals(
            new CurrentPath(self::STUDENT, self::PATH, self::MENTOR, new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500),
            $this->readModel->currentPath(self::STUDENT),
        );
        self::assertNull($this->readModel->currentPath(self::OTHER));
        self::assertNull($this->readModel->currentPath('not-a-uuid'));
    }

    public function testMentorSeesOnlyCurrentPaths(): void
    {
        $this->path(self::STUDENT, self::OLD_PATH, self::OLD_MENTOR, '2026-09-01 00:00:00');
        $this->path(self::STUDENT, self::PATH, self::MENTOR, '2026-10-01 00:00:00');
        $this->path(self::OTHER, self::OTHER_PATH, self::OLD_MENTOR, '2026-10-15 00:00:00', 600);

        self::assertEquals(
            [new CurrentPath(self::OTHER, self::OTHER_PATH, self::OLD_MENTOR, new \DateTimeImmutable('2026-10-15T00:00:00Z'), 600)],
            $this->readModel->currentPathsOfMentor(self::OLD_MENTOR),
        );
        self::assertSame([self::STUDENT], array_map(static fn (CurrentPath $path): string => $path->studentId, $this->readModel->currentPathsOfMentor(self::MENTOR)));
        self::assertSame([], $this->readModel->currentPathsOfMentor('not-a-uuid'));
    }

    public function testPathPlanTakesCompletionAndLogsOfPathTasks(): void
    {
        $this->path(self::STUDENT, self::PATH, self::MENTOR, '2026-10-01 00:00:00');
        $this->task(self::TASK_OPEN, 3300, 2);
        $this->task(self::TASK_DONE, 2700, 1);
        $this->progress(self::TASK_DONE, 'completed', '2026-10-10 12:00:00');
        $this->progress(self::TASK_OPEN, 'in_progress', null);
        $this->log(self::STUDENT, self::TASK_DONE, 'practice', 1500, '2026-10-05');
        $this->log(self::STUDENT, self::TASK_DONE, 'theory', 500, '2026-10-06');
        $this->log(self::STUDENT, self::TASK_OPEN, 'practice', 1300, '2026-10-12');
        // не входят в AC: без задания, задание вне пути, чужой ученик
        $this->log(self::STUDENT, null, 'interview', 90, '2026-10-12');
        $this->log(self::STUDENT, self::TASK_ELSEWHERE, 'practice', 60, '2026-10-12');
        $this->log(self::OTHER, self::TASK_DONE, 'practice', 70, '2026-10-12');

        $path = $this->readModel->currentPath(self::STUDENT);
        self::assertNotNull($path);

        self::assertEquals(new PathPlan(new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500, [
            new PlannedTask(2700, new \DateTimeImmutable('2026-10-10T12:00:00Z'), 2000),
            new PlannedTask(3300, null, 1300),
        ]), $this->readModel->pathPlan($path));
    }

    public function testSessionsOfStudent(): void
    {
        $this->connection->insert('activity_session', ['student_id' => self::STUDENT, 'task_id' => null, 'source' => 'theory', 'started_at' => '2026-11-03 10:00:00', 'last_seen_at' => '2026-11-03 10:30:00']);
        $this->connection->insert('activity_session', ['student_id' => self::STUDENT, 'task_id' => self::TASK_DONE, 'source' => 'sandbox', 'started_at' => '2026-11-02 09:00:00', 'last_seen_at' => '2026-11-02 09:05:00']);
        $this->connection->insert('activity_session', ['student_id' => self::OTHER, 'task_id' => null, 'source' => 'theory', 'started_at' => '2026-11-02 09:00:00', 'last_seen_at' => '2026-11-02 09:05:00']);

        self::assertEquals([
            new ActivityInterval(new \DateTimeImmutable('2026-11-02T09:00:00Z'), new \DateTimeImmutable('2026-11-02T09:05:00Z')),
            new ActivityInterval(new \DateTimeImmutable('2026-11-03T10:00:00Z'), new \DateTimeImmutable('2026-11-03T10:30:00Z')),
        ], $this->readModel->sessions(self::STUDENT));
    }

    public function testLoggedByCategoryWithinDates(): void
    {
        $this->log(self::STUDENT, self::TASK_DONE, 'practice', 400, '2026-11-02');
        $this->log(self::STUDENT, null, 'practice', 50, '2026-11-08');
        $this->log(self::STUDENT, null, 'interview', 90, '2026-11-04');
        $this->log(self::STUDENT, null, 'theory', 100, '2026-11-01');
        $this->log(self::OTHER, null, 'theory', 100, '2026-11-04');

        $logged = $this->readModel->loggedByCategory(self::STUDENT, new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-08'));

        self::assertEquals(['practice' => 450, 'interview' => 90], $logged);
    }

    public function testActiveSignalTypes(): void
    {
        $this->signal(SignalType::TaskStuck->value, 'student', self::TASK_DONE, true);
        $this->signal(SignalType::TaskStuck->value, 'mentor', self::TASK_DONE, true);
        $this->signal(SignalType::StudentInactive->value, 'student', null, false);
        $this->signal(SignalType::WeeklyGoalBehind->value, 'student', '2026-10-26', true);
        $this->signal(SignalType::StudentInactive->value, 'student', null, true, self::OTHER);

        self::assertSame([SignalType::TaskStuck], $this->readModel->activeSignalTypes(self::STUDENT, '2026-11-02'));

        $this->signal(SignalType::WeeklyGoalBehind->value, 'student', '2026-11-02', true);

        self::assertSame(
            [SignalType::TaskStuck, SignalType::WeeklyGoalBehind],
            $this->readModel->activeSignalTypes(self::STUDENT, '2026-11-02'),
        );
    }
}
