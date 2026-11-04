<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Progress\TimeCategory;

/**
 * Баланс: доли списанного времени по категориям без округления (SPEC §6.2; design, решение 6).
 */
final readonly class TimeBalance
{
    private function __construct(
        public float $theory,
        public float $practice,
        public float $interview,
    ) {
    }

    /**
     * @param array<value-of<TimeCategory>, int> $minutesByCategory нет категории — 0 минут
     */
    public static function of(array $minutesByCategory): self
    {
        $total = array_sum($minutesByCategory);
        // ничего не списано — все доли нулевые, а не «не посчитаны»
        $share = static fn (TimeCategory $category): float => $total > 0 ? ($minutesByCategory[$category->value] ?? 0) / $total : 0.0;

        return new self($share(TimeCategory::Theory), $share(TimeCategory::Practice), $share(TimeCategory::Interview));
    }
}
