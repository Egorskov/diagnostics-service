<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Metrics\PathMetrics;
use App\Domain\Metrics\Regularity;
use App\Domain\Metrics\TimeBalance;
use App\Domain\Signal\SignalType;

/**
 * Сводка ученика по текущему пути на момент запроса (spec student-summary); округление — забота представления.
 */
final readonly class StudentSummary
{
    /**
     * @param list<SignalType> $activeSignals
     */
    public function __construct(
        public string $studentId,
        public string $pathId,
        public PathMetrics $metrics,
        public int $weekGoalMinutes,
        public int $weekLoggedMinutes,
        public Regularity $regularity,
        public TimeBalance $balance,
        public array $activeSignals,
    ) {
    }
}
