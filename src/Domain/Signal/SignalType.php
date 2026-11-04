<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * Сигналы MVP (SPEC §7.2, §10); значение — event_type и routing key в diagnostics.events.
 */
enum SignalType: string
{
    case StudentInactive = 'diagnostics.student.inactive';
    case TaskStuck = 'diagnostics.task.stuck';
    case WeeklyGoalBehind = 'diagnostics.weekly_goal.behind';
}
