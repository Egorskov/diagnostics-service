<?php

declare(strict_types=1);

namespace App\Application\Handler;

use App\Application\Event\Incoming\TimeLogged;
use App\Application\Port\TimeLogRepository;
use App\Domain\Progress\TimeLog;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class TimeLoggedHandler
{
    public function __construct(private TimeLogRepository $logs)
    {
    }

    public function __invoke(TimeLogged $event): void
    {
        $this->logs->add(new TimeLog(
            $event->studentId,
            $event->taskId,
            $event->category,
            $event->minutes,
            $event->loggedForDate,
            $event->metadata()->eventId,
        ));
    }
}
