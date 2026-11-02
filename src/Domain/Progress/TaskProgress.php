<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Прогресс ученика по заданию (SPEC §5, task_progress).
 *
 * Результат не зависит от порядка событий: статус движется только вперёд,
 * а моменты начала и завершения хранят самое раннее значение (ADR 0003).
 */
class TaskProgress
{
    private TaskStatus $status = TaskStatus::NotStarted;
    private ?\DateTimeImmutable $startedAt = null;
    private ?\DateTimeImmutable $completedAt = null;
    private int $activeMinutes = 0;
    private int $failedRuns = 0;
    private int $maxHintLevel = 0;

    public function __construct(
        private string $studentId,
        private string $taskId,
        private string $pathId,
    ) {
    }

    public function start(\DateTimeImmutable $at): void
    {
        $this->startedAt = self::earliest($this->startedAt, $at);
        if (TaskStatus::NotStarted === $this->status) {
            $this->status = TaskStatus::InProgress;
        }
    }

    public function complete(\DateTimeImmutable $at): void
    {
        $this->completedAt = self::earliest($this->completedAt, $at);
        $this->status = TaskStatus::Completed;
    }

    public function recordFailedRun(): void
    {
        ++$this->failedRuns;
    }

    /**
     * Сумма длительностей сессий задания (SPEC §6.1, п. 5).
     */
    public function updateActiveMinutes(int $minutes): void
    {
        $this->activeMinutes = $minutes;
    }

    public function studentId(): string
    {
        return $this->studentId;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function pathId(): string
    {
        return $this->pathId;
    }

    public function status(): TaskStatus
    {
        return $this->status;
    }

    public function startedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function completedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function activeMinutes(): int
    {
        return $this->activeMinutes;
    }

    public function failedRuns(): int
    {
        return $this->failedRuns;
    }

    public function maxHintLevel(): int
    {
        return $this->maxHintLevel;
    }

    private static function earliest(?\DateTimeImmutable $current, \DateTimeImmutable $candidate): \DateTimeImmutable
    {
        return null === $current || $candidate < $current ? $candidate : $current;
    }
}
