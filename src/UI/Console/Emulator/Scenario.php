<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

/**
 * Входные данные эмулятора; null у ученика и ментора — id берутся из seed.
 */
final readonly class Scenario
{
    public const MIN_DAYS = 1;
    public const MAX_DAYS = 56;

    public function __construct(
        public Profile $profile,
        public int $days,
        public int $seed,
        public ?string $studentId = null,
        public ?string $mentorId = null,
    ) {
        if ($days < self::MIN_DAYS || $days > self::MAX_DAYS) {
            throw new \InvalidArgumentException(\sprintf('--days must be between %d and %d, got %d', self::MIN_DAYS, self::MAX_DAYS, $days));
        }
    }
}
