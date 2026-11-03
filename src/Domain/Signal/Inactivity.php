<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * Неактивность ученика (SPEC §7.2, student.inactive): целые сутки с последней активности.
 */
final readonly class Inactivity
{
    private const SECONDS_PER_DAY = 24 * 3600;

    public int $days;

    public function __construct(
        public \DateTimeImmutable $lastActivityAt,
        \DateTimeImmutable $now,
    ) {
        $this->days = max(0, intdiv($now->getTimestamp() - $lastActivityAt->getTimestamp(), self::SECONDS_PER_DAY));
    }

    public function reaches(int $thresholdDays): bool
    {
        return $this->days >= $thresholdDays;
    }
}
