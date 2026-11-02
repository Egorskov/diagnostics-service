<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\TaskProgressRepository;
use App\Domain\Progress\TaskProgress;

final class InMemoryTaskProgressRepository implements TaskProgressRepository
{
    /** @var array<string, TaskProgress> */
    public array $progress = [];

    public function findForUpdate(string $studentId, string $taskId): ?TaskProgress
    {
        return $this->progress[$studentId.'|'.$taskId] ?? null;
    }

    public function add(TaskProgress $progress): void
    {
        $this->progress[$progress->studentId().'|'.$progress->taskId()] = $progress;
    }
}
