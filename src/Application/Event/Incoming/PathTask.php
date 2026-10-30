<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

use App\Application\Event\PayloadReader;

/**
 * Задание в снапшоте пути (asyncapi.yaml → PathSnapshot.tasks[]).
 */
final readonly class PathTask
{
    public function __construct(
        public string $taskId,
        public string $stageId,
        public int $estimateMinutes,
        public int $position,
        public ?TaskKind $kind = null,
    ) {
    }

    public static function fromPayload(PayloadReader $task): self
    {
        return new self(
            taskId: $task->uuid('task_id'),
            stageId: $task->uuid('stage_id'),
            estimateMinutes: $task->int('estimate_minutes', min: 1),
            position: $task->int('position'),
            kind: $task->optionalEnum('kind', TaskKind::class),
        );
    }
}
