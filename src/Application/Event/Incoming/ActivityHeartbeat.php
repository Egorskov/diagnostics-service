<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

use App\Application\Event\EventMetadata;
use App\Application\Event\IncomingEvent;
use App\Application\Event\PayloadReader;

/**
 * `activity.heartbeat` v1 — раз в 60 с, пока вкладка активна. Момент активности — `metadata()->occurredAt`.
 */
final readonly class ActivityHeartbeat implements IncomingEvent
{
    public function __construct(
        private EventMetadata $metadata,
        public string $studentId,
        public ?string $taskId,
        public ActivitySource $source,
    ) {
    }

    public static function fromPayload(EventMetadata $metadata, PayloadReader $payload): static
    {
        return new self(
            $metadata,
            studentId: $payload->uuid('student_id'),
            taskId: $payload->optionalUuid('task_id'),
            source: $payload->enum('source', ActivitySource::class),
        );
    }

    public function metadata(): EventMetadata
    {
        return $this->metadata;
    }
}
