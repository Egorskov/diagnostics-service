<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\CurrentPath;
use App\Application\Port\ProgressReadModel;
use App\Domain\Metrics\ActivityInterval;
use App\Domain\Metrics\PathPlan;
use App\Domain\Metrics\PlannedTask;
use App\Domain\Signal\SignalType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

final readonly class DbalProgressReadModel implements ProgressReadModel
{
    // тот же выбор текущего пути, что в DbalSignalReadModel (design, решение 4)
    private const CURRENT_PATHS = <<<'SQL'
        SELECT DISTINCT ON (student_id) student_id, path_id, mentor_id, started_at, weekly_goal_minutes
        FROM student_path
        ORDER BY student_id, started_at DESC, path_id DESC
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    public function currentPath(string $studentId): ?CurrentPath
    {
        // колонки uuid: строка другого формата уронила бы запрос
        if (!Uuid::isValid($studentId)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM ('.self::CURRENT_PATHS.') path WHERE student_id = :student',
            ['student' => $studentId],
        );

        return false === $row ? null : self::currentPathFrom($row);
    }

    public function currentPathsOfMentor(string $mentorId): array
    {
        if (!Uuid::isValid($mentorId)) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM ('.self::CURRENT_PATHS.') path WHERE mentor_id = :mentor ORDER BY student_id',
            ['mentor' => $mentorId],
        );

        return array_map(self::currentPathFrom(...), $rows);
    }

    public function pathPlan(CurrentPath $path): PathPlan
    {
        $rows = $this->connection->fetchAllAssociative(<<<'SQL'
            SELECT task.estimate_minutes, progress.completed_at, coalesce(logs.minutes, 0) AS logged_minutes
            FROM path_task task
            LEFT JOIN task_progress progress
                ON progress.student_id = :student AND progress.task_id = task.task_id AND progress.status = 'completed'
            LEFT JOIN (
                SELECT task_id, sum(minutes) AS minutes
                FROM time_log
                WHERE student_id = :student AND task_id IS NOT NULL
                GROUP BY task_id
            ) logs ON logs.task_id = task.task_id
            WHERE task.path_id = :path
            ORDER BY task.position
            SQL, ['student' => $path->studentId, 'path' => $path->pathId]);

        return new PathPlan($path->startedAt, $path->weeklyGoalMinutes, array_map(static fn (array $row): PlannedTask => new PlannedTask(
            (int) $row['estimate_minutes'],
            null === $row['completed_at'] ? null : self::utc((string) $row['completed_at']),
            (int) $row['logged_minutes'],
        ), $rows));
    }

    public function sessions(string $studentId): array
    {
        if (!Uuid::isValid($studentId)) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT started_at, last_seen_at FROM activity_session WHERE student_id = :student ORDER BY started_at',
            ['student' => $studentId],
        );

        return array_map(static fn (array $row): ActivityInterval => new ActivityInterval(
            self::utc((string) $row['started_at']),
            self::utc((string) $row['last_seen_at']),
        ), $rows);
    }

    public function loggedByCategory(string $studentId, \DateTimeImmutable $fromDate, \DateTimeImmutable $toDate): array
    {
        if (!Uuid::isValid($studentId)) {
            return [];
        }
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT category, sum(minutes) FROM time_log WHERE student_id = :student AND logged_for_date BETWEEN :from AND :to GROUP BY category',
            ['student' => $studentId, 'from' => $fromDate->format('Y-m-d'), 'to' => $toDate->format('Y-m-d')],
        );

        return array_map(intval(...), $rows);
    }

    public function activeSignalTypes(string $studentId, string $weekSubject): array
    {
        if (!Uuid::isValid($studentId)) {
            return [];
        }
        // состояние weekly_goal.behind прошлых недель остаётся активным — берём только текущую
        $types = $this->connection->fetchFirstColumn(<<<'SQL'
            SELECT DISTINCT signal_type
            FROM signal_state
            WHERE student_id = :student AND active AND (signal_type <> :weekly OR subject_id = :week)
            ORDER BY signal_type
            SQL, ['student' => $studentId, 'weekly' => SignalType::WeeklyGoalBehind->value, 'week' => $weekSubject]);

        return array_map(static fn (mixed $type): SignalType => SignalType::from((string) $type), $types);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function currentPathFrom(array $row): CurrentPath
    {
        return new CurrentPath(
            (string) $row['student_id'],
            (string) $row['path_id'],
            (string) $row['mentor_id'],
            self::utc((string) $row['started_at']),
            (int) $row['weekly_goal_minutes'],
        );
    }

    /**
     * Время хранится в UTC без зоны (как пишет Doctrine datetime_immutable).
     */
    private static function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }
}
