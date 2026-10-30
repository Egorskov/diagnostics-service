<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

use App\Application\Event\EventMetadata;
use App\Application\Event\IncomingEvent;
use App\Application\Event\PayloadReader;

/**
 * `path.assigned` v1 — ментор назначил ученику путь; полный снапшот (asyncapi.yaml → PathSnapshot).
 */
final readonly class PathAssigned implements IncomingEvent
{
    /**
     * @param list<PathTask> $tasks
     */
    public function __construct(
        private EventMetadata $metadata,
        public string $studentId,
        public string $pathId,
        public string $mentorId,
        public \DateTimeImmutable $startedAt,
        public int $weeklyGoalMinutes,
        public array $tasks,
    ) {
    }

    public static function fromPayload(EventMetadata $metadata, PayloadReader $payload): static
    {
        return new self(
            $metadata,
            studentId: $payload->uuid('student_id'),
            pathId: $payload->uuid('path_id'),
            mentorId: $payload->uuid('mentor_id'),
            startedAt: $payload->dateTime('started_at'),
            weeklyGoalMinutes: $payload->int('weekly_goal_minutes'),
            tasks: array_map(PathTask::fromPayload(...), $payload->list('tasks')),
        );
    }

    public function metadata(): EventMetadata
    {
        return $this->metadata;
    }
}
