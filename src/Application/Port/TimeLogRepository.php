<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Domain\Progress\TimeLog;

interface TimeLogRepository
{
    public function add(TimeLog $log): void;
}
