<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Путь ученика для расчёта метрик (SPEC §6.2): только задания текущего состава пути.
 */
final readonly class PathPlan
{
    /**
     * @param list<PlannedTask> $tasks
     */
    public function __construct(
        public \DateTimeImmutable $startedAt,
        public int $weeklyGoalMinutes,
        public array $tasks,
    ) {
    }
}
