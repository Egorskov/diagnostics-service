<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Progress\TaskProgress;

interface TaskProgressRepository
{
    /**
     * Блокирует строку до конца транзакции: параллельное событие не потеряет обновление счётчика.
     */
    public function findForUpdate(string $studentId, string $taskId): ?TaskProgress;

    public function add(TaskProgress $progress): void;
}
