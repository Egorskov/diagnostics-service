<?php

declare(strict_types=1);

namespace App\Domain\Signal;

/**
 * asyncapi.yaml → TaskStuck.payload.reason.
 */
enum StuckReason: string
{
    case TimeExceeded = 'time_exceeded';
    case FailedRuns = 'failed_runs';
}
