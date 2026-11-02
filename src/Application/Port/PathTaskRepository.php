<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Progress\PathTask;

interface PathTaskRepository
{
    /**
     * @return list<PathTask> в порядке position
     */
    public function forPath(string $pathId): array;

    public function add(PathTask $task): void;

    public function remove(PathTask $task): void;
}
