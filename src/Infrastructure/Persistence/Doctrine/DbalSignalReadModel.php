<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\SignalReadModel;
use App\Application\Signal\SignalStudent;
use Doctrine\DBAL\Connection;

final readonly class DbalSignalReadModel implements SignalReadModel
{
    public function __construct(private Connection $connection)
    {
    }

    public function studentsWithCurrentPath(): array
    {
        // текущий путь — самый поздний started_at, при равенстве — наибольший path_id
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT path.student_id, path.mentor_id, path.started_at, path.weekly_goal_minutes, activity.last_seen_at
            FROM (
                SELECT DISTINCT ON (student_id) student_id, mentor_id, started_at, weekly_goal_minutes
                FROM student_path
                ORDER BY student_id, started_at DESC, path_id DESC
            ) path
            LEFT JOIN (
                SELECT student_id, max(last_seen_at) AS last_seen_at
                FROM activity_session
                GROUP BY student_id
            ) activity ON activity.student_id = path.student_id
            ORDER BY path.student_id
            SQL);

        return array_map(static fn (array $row): SignalStudent => new SignalStudent(
            (string) $row['student_id'],
            (string) $row['mentor_id'],
            self::utc((string) $row['started_at']),
            (int) $row['weekly_goal_minutes'],
            null === $row['last_seen_at'] ? null : self::utc((string) $row['last_seen_at']),
        ), $rows);
    }

    public function loggedMinutes(\DateTimeImmutable $fromDate, \DateTimeImmutable $toDate): array
    {
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT student_id, sum(minutes) FROM time_log WHERE logged_for_date BETWEEN :from AND :to GROUP BY student_id',
            ['from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')],
        );

        return array_map(intval(...), $rows);
    }

    /**
     * Время хранится в UTC без зоны (как пишет Doctrine datetime_immutable).
     */
    private static function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
