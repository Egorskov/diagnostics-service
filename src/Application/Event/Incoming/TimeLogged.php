<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

use App\Application\Event\EventMetadata;
use App\Application\Event\IncomingEvent;
use App\Application\Event\PayloadReader;

/**
 * `time.logged` v1 — ученик списал время вручную (владелец сервиса — SPEC §11, вопрос 1).
 */
final readonly class TimeLogged implements IncomingEvent
{
    public function __construct(
        private EventMetadata $metadata,
        public string $studentId,
        public ?string $taskId,
        public int $minutes,
        public TimeCategory $category,
        /** День, за который списано время: полночь UTC */
        public \DateTimeImmutable $loggedForDate,
    ) {
    }

    public static function fromPayload(EventMetadata $metadata, PayloadReader $payload): static
    {
        return new self(
            $metadata,
            studentId: $payload->uuid('student_id'),
            taskId: $payload->optionalUuid('task_id'),
            minutes: $payload->int('minutes', min: 1),
            category: $payload->enum('category', TimeCategory::class),
            loggedForDate: $payload->date('logged_for_date'),
        );
    }

    public function metadata(): EventMetadata
    {
        return $this->metadata;
    }
}
