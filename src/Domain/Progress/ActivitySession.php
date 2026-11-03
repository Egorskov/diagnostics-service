<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Сессия активности ученика (SPEC §3, §5, activity_session; расчёт — §6.1).
 *
 * Границы только расширяются: опоздавшая точка не сокращает сессию. Нижняя граница окна
 * реализует правило §6.1 про опоздания больше таймаута (design change add-activity-sessions, п. 2).
 */
class ActivitySession
{
    /** @phpstan-ignore property.unusedType (значение назначает база при сохранении, через Doctrine) */
    private ?int $id = null;
    private \DateTimeImmutable $startedAt;
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(
        private string $studentId,
        private ?string $taskId,
        private ActivitySource $source,
        \DateTimeImmutable $at,
    ) {
        $this->startedAt = $at;
        $this->lastSeenAt = $at;
    }

    public function covers(\DateTimeImmutable $at, int $idleMinutes): bool
    {
        $idle = new \DateInterval(\sprintf('PT%dM', $idleMinutes));

        return $at >= $this->startedAt->sub($idle) && $at <= $this->lastSeenAt->add($idle);
    }

    public function touch(\DateTimeImmutable $at): void
    {
        $this->startedAt = min($this->startedAt, $at);
        $this->lastSeenAt = max($this->lastSeenAt, $at);
    }

    /**
     * Длительность в целых минутах: last_seen_at − started_at + 1 мин, неполная минута разности отбрасывается.
     */
    public function minutes(): int
    {
        return (int) floor(($this->lastSeenAt->getTimestamp() - $this->startedAt->getTimestamp()) / 60) + 1;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function studentId(): string
    {
        return $this->studentId;
    }

    public function taskId(): ?string
    {
        return $this->taskId;
    }

    public function source(): ActivitySource
    {
        return $this->source;
    }

    public function startedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function lastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
