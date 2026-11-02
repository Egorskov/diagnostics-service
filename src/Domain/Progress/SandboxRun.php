<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Запуск решения в песочнице (SPEC §5, sandbox_run).
 */
class SandboxRun
{
    public function __construct(
        private string $runId,
        private string $studentId,
        private string $taskId,
        private SandboxResult $result,
        private ?string $errorType,
        private \DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Неудачный запуск — всё, что не `passed`: SPEC §6.3 и §7.2 не разделяют `failed` и `error`.
     */
    public function isFailed(): bool
    {
        return SandboxResult::Passed !== $this->result;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    public function studentId(): string
    {
        return $this->studentId;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function result(): SandboxResult
    {
        return $this->result;
    }

    public function errorType(): ?string
    {
        return $this->errorType;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
