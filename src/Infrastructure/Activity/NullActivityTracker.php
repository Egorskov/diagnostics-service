<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Application\Port\ActivityPoint;
use App\Application\Port\ActivityTracker;

/**
 * Заглушка до сессионизации (шаг 4 MVP): точки активности отбрасываются.
 * Реальный поток событий подключать только после её замены.
 */
final class NullActivityTracker implements ActivityTracker
{
    public function record(ActivityPoint $point): void
    {
    }
}
