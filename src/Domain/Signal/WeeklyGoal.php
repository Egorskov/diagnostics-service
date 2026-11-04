<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * Отставание от недельной цели (SPEC §7.2, weekly_goal.behind): списано меньше доли цели.
 */
final readonly class WeeklyGoal
{
    public static function isBehind(int $goalMinutes, int $loggedMinutes, float $minRatio): bool
    {
        return $goalMinutes > 0 && $loggedMinutes < $minRatio * $goalMinutes;
    }
}
