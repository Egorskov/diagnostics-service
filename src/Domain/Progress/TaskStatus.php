<?php

declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Статус задания у ученика (SPEC §5, task_progress.status).
 */
enum TaskStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
