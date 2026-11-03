<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * Застревание на задании (SPEC §7.2, task.stuck): активное время > ratio × оценки или неудачных запусков ≥ порога.
 */
final readonly class TaskStuck
{
    /**
     * @param int|null $estimateMinutes null — задания нет в составе пути, проверяются только запуски
     *
     * @return StuckReason|null null — условие ложно; при обоих условиях причина — время
     */
    public static function reason(
        bool $completed,
        int $activeMinutes,
        ?int $estimateMinutes,
        int $failedRuns,
        float $timeRatio,
        int $maxFailedRuns,
    ): ?StuckReason {
        if ($completed) {
            return null;
        }
        if (null !== $estimateMinutes && $activeMinutes > $timeRatio * $estimateMinutes) {
            return StuckReason::TimeExceeded;
        }

        return $failedRuns >= $maxFailedRuns ? StuckReason::FailedRuns : null;
    }
}
