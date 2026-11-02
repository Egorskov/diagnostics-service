<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Задание пути с оценкой ментора (SPEC §5, path_task).
 */
class PathTask
{
    public function __construct(
        private string $pathId,
        private string $taskId,
        private string $stageId,
        private int $estimateMinutes,
        private int $position,
    ) {
    }

    public function updateFromSnapshot(string $stageId, int $estimateMinutes, int $position): void
    {
        $this->stageId = $stageId;
        $this->estimateMinutes = $estimateMinutes;
        $this->position = $position;
    }

    public function pathId(): string
    {
        return $this->pathId;
    }

    public function taskId(): string
    {
        return $this->taskId;
    }

    public function stageId(): string
    {
        return $this->stageId;
    }

    public function estimateMinutes(): int
    {
        return $this->estimateMinutes;
    }

    public function position(): int
    {
        return $this->position;
    }
}
