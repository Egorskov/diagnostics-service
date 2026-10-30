<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Event\Incoming\TaskCompleted;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Хендлер-заглушка для функциональных тестов шины, пока настоящих хендлеров нет (шаг 3).
 */
#[AsMessageHandler]
final class RecordingEventHandler
{
    /** @var list<TaskCompleted> */
    public array $handled = [];

    public function __invoke(TaskCompleted $event): void
    {
        $this->handled[] = $event;
    }
}
