<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Event\EventMetadata;
use App\Application\Port\TaskStuckCheck;

final class RecordingTaskStuckCheck implements TaskStuckCheck
{
    /** @var list<array{string, string, string}> ученик, задание, event_id причины */
    public array $checks = [];

    public function check(string $studentId, string $taskId, EventMetadata $cause): void
    {
        $this->checks[] = [$studentId, $taskId, $cause->eventId];
    }
}
