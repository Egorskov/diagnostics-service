<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Запись о списанном времени (SPEC §5, time_log). Каждое списание — отдельный факт.
 */
class TimeLog
{
    /** @phpstan-ignore property.unusedType (значение назначает база при сохранении, через Doctrine) */
    private ?int $id = null;

    public function __construct(
        private string $studentId,
        private ?string $taskId,
        private TimeCategory $category,
        private int $minutes,
        private \DateTimeImmutable $loggedForDate,
        private string $sourceEventId,
    ) {
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

    public function category(): TimeCategory
    {
        return $this->category;
    }

    public function minutes(): int
    {
        return $this->minutes;
    }

    public function loggedForDate(): \DateTimeImmutable
    {
        return $this->loggedForDate;
    }

    public function sourceEventId(): string
    {
        return $this->sourceEventId;
    }
}
