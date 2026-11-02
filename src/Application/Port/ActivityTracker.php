<?php

declare(strict_types=1);

namespace App\Application\Port;

interface ActivityTracker
{
    public function record(ActivityPoint $point): void;
}
