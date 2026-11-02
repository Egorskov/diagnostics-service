<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Путь, назначенный ученику ментором (SPEC §5, student_path).
 */
class StudentPath
{
    public function __construct(
        private string $studentId,
        private string $pathId,
        private string $mentorId,
        private \DateTimeImmutable $startedAt,
        private int $weeklyGoalMinutes,
    ) {
    }

    public function updateFromSnapshot(string $mentorId, \DateTimeImmutable $startedAt, int $weeklyGoalMinutes): void
    {
        $this->mentorId = $mentorId;
        $this->startedAt = $startedAt;
        $this->weeklyGoalMinutes = $weeklyGoalMinutes;
    }

    public function studentId(): string
    {
        return $this->studentId;
    }

    public function pathId(): string
    {
        return $this->pathId;
    }

    public function mentorId(): string
    {
        return $this->mentorId;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function weeklyGoalMinutes(): int
    {
        return $this->weeklyGoalMinutes;
    }
}
