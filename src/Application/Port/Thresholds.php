<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Signal\WeeklyMoment;

/**
 * Пороги SPEC §7.1 из таблицы threshold. Значения в коде нет намеренно (ADR 0004).
 * Каждый метод может бросить InvalidThreshold.
 */
interface Thresholds
{
    /**
     * @throws InvalidThreshold
     */
    public function idleTimeoutMinutes(): int;

    /**
     * @throws InvalidThreshold
     */
    public function inactiveStudentDays(): int;

    /**
     * @throws InvalidThreshold
     */
    public function inactiveMentorDays(): int;

    /**
     * @throws InvalidThreshold
     */
    public function stuckTimeRatio(): float;

    /**
     * @throws InvalidThreshold
     */
    public function stuckFailedRuns(): int;

    /**
     * @throws InvalidThreshold
     */
    public function stuckEscalationHours(): int;

    /**
     * @throws InvalidThreshold
     */
    public function weeklyGoalMinRatio(): float;

    /**
     * @throws InvalidThreshold
     */
    public function weeklyGoalCheck(): WeeklyMoment;
}
