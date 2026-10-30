<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

use App\Application\Event\EventMetadata;
use App\Application\Event\IncomingEvent;
use App\Application\Event\PayloadReader;

/**
 * `task.completed` v1 — сервис Курсов.
 */
final readonly class TaskCompleted implements IncomingEvent
{
    public function __construct(
        private EventMetadata $metadata,
        public string $studentId,
        public string $pathId,
        public string $taskId,
    ) {
    }

    public static function fromPayload(EventMetadata $metadata, PayloadReader $payload): static
    {
        return new self(
            $metadata,
            studentId: $payload->uuid('student_id'),
            pathId: $payload->uuid('path_id'),
            taskId: $payload->uuid('task_id'),
        );
    }

    public function metadata(): EventMetadata
    {
        return $this->metadata;
    }
}
