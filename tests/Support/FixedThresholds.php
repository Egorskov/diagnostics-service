<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\InvalidThreshold;
use App\Application\Port\Thresholds;
use App\Domain\Signal\WeeklyMoment;

/**
 * Значения по умолчанию — из SPEC §7.1; null — порог не задан.
 */
final readonly class FixedThresholds implements Thresholds
{
    public function __construct(
        private ?int $idleTimeoutMinutes = 15,
        private ?int $inactiveStudentDays = 3,
        private ?int $inactiveMentorDays = 7,
        private ?float $stuckTimeRatio = 1.5,
        private ?int $stuckFailedRuns = 10,
        private ?int $stuckEscalationHours = 24,
        private ?float $weeklyGoalMinRatio = 0.5,
        private ?WeeklyMoment $weeklyGoalCheck = null,
    ) {
    }

    public function idleTimeoutMinutes(): int
    {
        return $this->idleTimeoutMinutes ?? throw self::missing();
    }

    public function inactiveStudentDays(): int
    {
        return $this->inactiveStudentDays ?? throw self::missing();
    }

    public function inactiveMentorDays(): int
    {
        return $this->inactiveMentorDays ?? throw self::missing();
    }

    public function stuckTimeRatio(): float
    {
        return $this->stuckTimeRatio ?? throw self::missing();
    }

    public function stuckFailedRuns(): int
    {
        return $this->stuckFailedRuns ?? throw self::missing();
    }

    public function stuckEscalationHours(): int
    {
        return $this->stuckEscalationHours ?? throw self::missing();
    }

    public function weeklyGoalMinRatio(): float
    {
        return $this->weeklyGoalMinRatio ?? throw self::missing();
    }

    public function weeklyGoalCheck(): WeeklyMoment
    {
        return $this->weeklyGoalCheck ?? new WeeklyMoment(4, 18, 0, new \DateTimeZone('Europe/Belgrade'));
    }

    private static function missing(): InvalidThreshold
    {
        return new InvalidThreshold('Threshold is not set');
    }
}
