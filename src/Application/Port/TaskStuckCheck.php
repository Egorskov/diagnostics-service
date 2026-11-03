<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Event\EventMetadata;

/**
 * Проверка task.stuck после изменения прогресса (SPEC §7.2): вызывают хендлеры sandbox.run, activity.heartbeat,
 * task.completed в своей транзакции.
 */
interface TaskStuckCheck
{
    public function check(string $studentId, string $taskId, EventMetadata $cause): void;
}
