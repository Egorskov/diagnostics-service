<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Регулярность ученика на момент «сейчас» (SPEC §6.2): активные дни и минуты текущей недели, серия.
 * Неделя — с понедельника по воскресенье, дни — календарные в зоне расчёта (design, решение 5).
 */
final readonly class Regularity
{
    private function __construct(
        public int $activeDaysInWeek,
        public int $streakDays,
        public int $weekActiveMinutes,
    ) {
    }

    /**
     * @param list<ActivityInterval> $sessions все сессии ученика, с заданием и без
     */
    public static function calculate(array $sessions, \DateTimeImmutable $now, \DateTimeZone $zone): self
    {
        $today = self::day($now, $zone);
        $weekStart = $today->modify(\sprintf('-%d days', (int) $today->format('N') - 1));
        $nextWeek = $weekStart->modify('+7 days');

        $activeDays = [];
        $weekMinutes = 0;
        foreach ($sessions as $session) {
            // день активен, если с ним пересекается сессия: через полночь — оба дня
            $last = self::day($session->lastSeenAt, $zone);
            for ($day = self::day($session->startedAt, $zone); $day <= $last; $day = $day->modify('+1 day')) {
                $activeDays[$day->format('Y-m-d')] = true;
            }
            // минуты — неделе, в которой сессия началась
            if ($session->startedAt >= $weekStart && $session->startedAt < $nextWeek) {
                $weekMinutes += $session->minutes();
            }
        }

        $daysInWeek = 0;
        for ($day = $weekStart; $day < $nextWeek; $day = $day->modify('+1 day')) {
            $daysInWeek += isset($activeDays[$day->format('Y-m-d')]) ? 1 : 0;
        }

        return new self($daysInWeek, self::streak($activeDays, $today), $weekMinutes);
    }

    /**
     * Подряд идущие активные дни до сегодня; пока сегодня не активно — до вчера, чтобы серия не обнулялась утром.
     *
     * @param array<string, true> $activeDays
     */
    private static function streak(array $activeDays, \DateTimeImmutable $today): int
    {
        $day = isset($activeDays[$today->format('Y-m-d')]) ? $today : $today->modify('-1 day');
        $streak = 0;
        while (isset($activeDays[$day->format('Y-m-d')])) {
            ++$streak;
            $day = $day->modify('-1 day');
        }

        return $streak;
    }

    private static function day(\DateTimeImmutable $at, \DateTimeZone $zone): \DateTimeImmutable
    {
        return $at->setTimezone($zone)->setTime(0, 0);
    }
}
