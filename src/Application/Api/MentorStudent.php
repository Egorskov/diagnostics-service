<?php

declare(strict_types=1);

namespace App\Application\Api;

use App\Domain\Metrics\PathMetrics;
use App\Domain\Signal\SignalType;

/**
 * Ученик в списке ментора (spec mentor-students).
 */
final readonly class MentorStudent
{
    /**
     * @param list<SignalType> $activeSignals
     */
    public function __construct(
        public string $studentId,
        public string $pathId,
        public PathMetrics $metrics,
        public \DateTimeImmutable $lastActivityAt,
        public array $activeSignals,
    ) {
    }
}
