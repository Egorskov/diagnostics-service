<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\ActivityPoint;
use App\Application\Port\ActivityTracker;

final class RecordingActivityTracker implements ActivityTracker
{
    /** @var list<ActivityPoint> */
    public array $points = [];

    public function record(ActivityPoint $point): void
    {
        $this->points[] = $point;
    }
}
