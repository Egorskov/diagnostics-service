<?php

declare(strict_types=1);

namespace App\Application\Signal;

/**
 * Ученик для проверок расписания: текущий путь (самый поздний started_at) и последняя сессия активности.
 */
final readonly class SignalStudent
{
    public function __construct(
        public string $studentId,
        public string $mentorId,
        public \DateTimeImmutable $pathStartedAt,
        public int $weeklyGoalMinutes,
        public ?\DateTimeImmutable $lastSeenAt,
    ) {
    }

    /**
     * Последняя активность; без сессий — начало текущего пути (spec «Последняя активность»).
     */
    public function lastActivityAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt ?? $this->pathStartedAt;
    }
}
