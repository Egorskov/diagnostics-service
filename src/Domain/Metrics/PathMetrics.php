<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Метрики прогресса ученика по пути на момент «сейчас» (SPEC §6.2), все объёмы — в минутах.
 */
final readonly class PathMetrics
{
    private const SECONDS_PER_WEEK = 7 * 24 * 3600;

    /** EV / PV, не считается при PV = 0 */
    public ?float $spi;
    /** EV / AC, не считается при AC = 0 */
    public ?float $cpi;

    /**
     * Даты — полночь календарного дня в зоне расчёта.
     */
    private function __construct(
        public int $earnedMinutes,
        public int $plannedMinutes,
        public int $actualMinutes,
        public ?\DateTimeImmutable $plannedFinish,
    ) {
        $this->spi = $plannedMinutes > 0 ? $earnedMinutes / $plannedMinutes : null;
        $this->cpi = $actualMinutes > 0 ? $earnedMinutes / $actualMinutes : null;
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

        return new self(
            $earned,
            min($total, self::plannedByGoal($plan, $now)),
            $actual,
            self::plannedFinish($plan, $total, $zone),
        );
    }

    /**
     * Дата начала + Σ оценок / недельная цель недель; неполный день считается целым.
     */
    private static function plannedFinish(PathPlan $plan, int $total, \DateTimeZone $zone): ?\DateTimeImmutable
    {
        if ($plan->weeklyGoalMinutes <= 0) {
            return null;
        }

        return self::addDays(self::day($plan->startedAt, $zone), self::ceilDiv($total * 7, $plan->weeklyGoalMinutes));
    }

    private static function day(\DateTimeImmutable $at, \DateTimeZone $zone): \DateTimeImmutable
    {
        return $at->setTimezone($zone)->setTime(0, 0);
    }

    private static function addDays(\DateTimeImmutable $day, int $days): \DateTimeImmutable
    {
        return $day->modify(\sprintf('%+d days', $days));
    }

    private static function ceilDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
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
