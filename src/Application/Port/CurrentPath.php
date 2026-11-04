<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Текущий путь ученика: самый поздний started_at, при равенстве — наибольший path_id (spec «Сводка по текущему пути»).
 */
final readonly class CurrentPath
{
    public function __construct(
        public string $studentId,
        public string $pathId,
        public string $mentorId,
        public \DateTimeImmutable $startedAt,
        public int $weeklyGoalMinutes,
    ) {
    }
}
