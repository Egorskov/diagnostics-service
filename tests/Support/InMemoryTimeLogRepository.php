<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\TimeLogRepository;
use App\Domain\Progress\TimeLog;

final class InMemoryTimeLogRepository implements TimeLogRepository
{
    /** @var list<TimeLog> */
    public array $logs = [];

    public function add(TimeLog $log): void
    {
        $this->logs[] = $log;
    }
}
