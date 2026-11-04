<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Application\Signal\SignalStudent;
use App\Infrastructure\Persistence\Doctrine\DbalSignalReadModel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DbalSignalReadModelTest extends KernelTestCase
{
    private const STUDENT = '11111111-1111-4111-8111-111111111111';
    private const NEWCOMER = '22222222-2222-4222-8222-222222222222';

    private Connection $connection;
    private DbalSignalReadModel $readModel;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        foreach (['student_path', 'activity_session', 'time_log'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->readModel = new DbalSignalReadModel($this->connection);
    }

    private function path(string $student, string $path, string $mentor, string $startedAt, int $goal): void
    {
        $this->connection->insert('student_path', ['student_id' => $student, 'path_id' => $path, 'mentor_id' => $mentor, 'started_at' => $startedAt, 'weekly_goal_minutes' => $goal]);
    }

    private function session(?string $task, string $lastSeenAt): void
    {
        $this->connection->insert('activity_session', ['student_id' => self::STUDENT, 'task_id' => $task, 'source' => 'theory', 'started_at' => $lastSeenAt, 'last_seen_at' => $lastSeenAt]);
    }

    private function log(string $student, ?string $task, int $minutes, string $date): void
    {
        $this->connection->insert('time_log', ['student_id' => $student, 'task_id' => $task, 'category' => 'practice', 'minutes' => $minutes, 'logged_for_date' => $date, 'source_event_id' => \sprintf('77777777-7777-4777-8777-%012d', random_int(0, 999999999))]);
    }

    public function testCurrentPathAndLastActivity(): void
    {
        $this->path(self::STUDENT, 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-00000000000a', '2026-09-01 00:00:00', 1000);
        $this->path(self::STUDENT, 'aaaaaaaa-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-00000000000b', '2026-10-01 00:00:00', 1500);
        $this->path(self::NEWCOMER, 'aaaaaaaa-0000-4000-8000-000000000003', 'aaaaaaaa-0000-4000-8000-00000000000c', '2026-10-15 00:00:00', 600);
        $this->session('33333333-3333-4333-8333-333333333333', '2026-10-29 10:00:00');
        $this->session(null, '2026-10-30 10:00:00');

        $students = $this->readModel->studentsWithCurrentPath();

        self::assertEquals([
            new SignalStudent(self::STUDENT, 'aaaaaaaa-0000-4000-8000-00000000000b', new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500, new \DateTimeImmutable('2026-10-30T10:00:00Z')),
            new SignalStudent(self::NEWCOMER, 'aaaaaaaa-0000-4000-8000-00000000000c', new \DateTimeImmutable('2026-10-15T00:00:00Z'), 600, null),
        ], $students);
        self::assertEquals(new \DateTimeImmutable('2026-10-15T00:00:00Z'), $students[1]->lastActivityAt());
    }

    public function testSameStartPicksGreatestPathId(): void
    {
        $this->path(self::STUDENT, 'aaaaaaaa-0000-4000-8000-000000000001', 'aaaaaaaa-0000-4000-8000-00000000000a', '2026-10-01 00:00:00', 1000);
        $this->path(self::STUDENT, 'aaaaaaaa-0000-4000-8000-000000000002', 'aaaaaaaa-0000-4000-8000-00000000000b', '2026-10-01 00:00:00', 1500);

        self::assertSame('aaaaaaaa-0000-4000-8000-00000000000b', $this->readModel->studentsWithCurrentPath()[0]->mentorId);
    }

    public function testLoggedMinutesWithinDatesIncludingLogsWithoutTask(): void
    {
        $this->log(self::STUDENT, null, 30, '2026-11-02');
        $this->log(self::STUDENT, '33333333-3333-4333-8333-333333333333', 45, '2026-11-08');
        $this->log(self::STUDENT, null, 100, '2026-11-01');
        $this->log(self::STUDENT, null, 100, '2026-11-09');
        $this->log(self::NEWCOMER, null, 20, '2026-11-05');

        // порядок учеников не важен
        self::assertEquals(
            [self::STUDENT => 75, self::NEWCOMER => 20],
            $this->readModel->loggedMinutes(new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-08')),
        );
    }
}
