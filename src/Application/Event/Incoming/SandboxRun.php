<?php

declare(strict_types=1);

namespace App\Application\Event\Incoming;

use App\Application\Event\EventMetadata;
use App\Application\Event\IncomingEvent;
use App\Application\Event\PayloadReader;
use App\Domain\Progress\SandboxResult;

/**
 * `sandbox.run` v1 — запуск решения в песочнице.
 */
final readonly class SandboxRun implements IncomingEvent
{
    public function __construct(
        private EventMetadata $metadata,
        public string $runId,
        public string $studentId,
        public string $taskId,
        public SandboxResult $result,
        public ?int $testsPassed = null,
        public ?int $testsTotal = null,
        /** Класс ошибки, напр. TypeError, Timeout */
        public ?string $errorType = null,
    ) {
    }

    public static function fromPayload(EventMetadata $metadata, PayloadReader $payload): static
    {
        return new self(
            $metadata,
            runId: $payload->uuid('run_id'),
            studentId: $payload->uuid('student_id'),
            taskId: $payload->uuid('task_id'),
            result: $payload->enum('result', SandboxResult::class),
            testsPassed: $payload->optionalInt('tests_passed'),
            testsTotal: $payload->optionalInt('tests_total'),
            errorType: $payload->optionalString('error_type'),
        );
    }

    public function metadata(): EventMetadata
    {
        return $this->metadata;
    }
}
