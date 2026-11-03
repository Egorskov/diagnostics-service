<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Метрики прогресса ученика по пути на момент «сейчас» (SPEC §6.2), все объёмы — в минутах.
 */
final readonly class PathMetrics
{
    private const SECONDS_PER_WEEK = 7 * 24 * 3600;

    private function __construct(
        public int $earnedMinutes,
        public int $plannedMinutes,
        public int $actualMinutes,
    ) {
    }

    public static function calculate(PathPlan $plan, \DateTimeImmutable $now, \DateTimeZone $zone): self
    {
        $total = 0;
        $earned = 0;
        $actual = 0;
        foreach ($plan->tasks as $task) {
            $total += $task->estimateMinutes;
            $earned += $task->isCompleted() ? $task->estimateMinutes : 0;
            $actual += $task->loggedMinutes;
        }

        return new self($earned, min($total, self::plannedByGoal($plan, $now)), $actual);
    }

    /**
     * Недельная цель × дробное число недель с начала. Считается в целых числах: 1500 × 2,4 во float
     * даёт 3599,99…, и floor потерял бы минуту.
     */
    private static function plannedByGoal(PathPlan $plan, \DateTimeImmutable $now): int
    {
        $seconds = $now->getTimestamp() - $plan->startedAt->getTimestamp();
        if ($seconds <= 0 || $plan->weeklyGoalMinutes <= 0) {
            return 0;
        }

        return intdiv($plan->weeklyGoalMinutes * $seconds, self::SECONDS_PER_WEEK);
    }
}
