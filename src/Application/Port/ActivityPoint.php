<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Progress\ActivitySource;

/**
 * Точка активности ученика (SPEC §3): из них шаг 4 MVP собирает сессии.
 */
final readonly class ActivityPoint
{
    public function __construct(
        public string $studentId,
        public ?string $taskId,
        public ActivitySource $source,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
