<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Задание пути для расчёта метрик: оценка ментора, момент закрытия и списанное по нему время.
 */
final readonly class PlannedTask
{
    public function __construct(
        public int $estimateMinutes,
        public ?\DateTimeImmutable $completedAt,
        public int $loggedMinutes,
    ) {
        if ($estimateMinutes < 1) {
            throw new \InvalidArgumentException(\sprintf('Оценка задания должна быть не меньше 1 минуты, получено %d.', $estimateMinutes));
        }
        if ($loggedMinutes < 0) {
            throw new \InvalidArgumentException(\sprintf('Списанное время не может быть отрицательным, получено %d.', $loggedMinutes));
        }
    }

    public function isCompleted(): bool
    {
        return null !== $this->completedAt;
    }
}
