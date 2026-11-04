<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * Момент недели в зоне, например «четверг 18:00 Europe/Belgrade» (SPEC §7.1, weekly_goal_check).
 * Неделя — с понедельника по воскресенье в этой зоне.
 */
final readonly class WeeklyMoment
{
    public function __construct(
        public int $isoDay,
        public int $hour,
        public int $minute,
        public \DateTimeZone $zone,
    ) {
        if ($isoDay < 1 || $isoDay > 7 || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
            throw new \InvalidArgumentException(\sprintf('Invalid weekly moment: day %d, %02d:%02d', $isoDay, $hour, $minute));
        }
    }

    /**
     * Полночь понедельника недели, в которую попадает $now.
     */
    public function weekStart(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $local = $now->setTimezone($this->zone)->setTime(0, 0);

        return $local->modify(\sprintf('-%d days', (int) $local->format('N') - 1));
    }

    public function occurrence(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $this->weekStart($now)
            ->modify(\sprintf('+%d days', $this->isoDay - 1))
            ->setTime($this->hour, $this->minute);
    }

    public function hasPassed(\DateTimeImmutable $now): bool
    {
        return $now >= $this->occurrence($now);
    }
}
