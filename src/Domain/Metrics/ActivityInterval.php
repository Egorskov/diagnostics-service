<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

/**
 * Отрезок сессии активности ученика: от started_at до last_seen_at (SPEC §6.1).
 */
final readonly class ActivityInterval
{
    public function __construct(
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $lastSeenAt,
    ) {
        if ($lastSeenAt < $startedAt) {
            throw new \InvalidArgumentException(\sprintf('Сессия не может закончиться раньше начала: %s < %s.', $lastSeenAt->format(\DATE_ATOM), $startedAt->format(\DATE_ATOM)));
        }
    }

    /**
     * Длительность в целых минутах, как у сессии: last_seen_at − started_at + 1 мин (SPEC §6.1, п. 4).
     */
    public function minutes(): int
    {
        return intdiv($this->lastSeenAt->getTimestamp() - $this->startedAt->getTimestamp(), 60) + 1;
    }
}
