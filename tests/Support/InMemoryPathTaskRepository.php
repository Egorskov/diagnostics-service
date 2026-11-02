<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\PathTaskRepository;
use App\Domain\Progress\PathTask;

final class InMemoryPathTaskRepository implements PathTaskRepository
{
    /** @var array<string, PathTask> */
    private array $tasks = [];

    public function forPath(string $pathId): array
    {
        $tasks = array_values(array_filter($this->tasks, static fn (PathTask $task) => $task->pathId() === $pathId));
        usort($tasks, static fn (PathTask $a, PathTask $b) => $a->position() <=> $b->position());

        return $tasks;
    }

    public function add(PathTask $task): void
    {
        $this->tasks[$task->pathId().'|'.$task->taskId()] = $task;
    }

    public function remove(PathTask $task): void
    {
        unset($this->tasks[$task->pathId().'|'.$task->taskId()]);
    }
}
