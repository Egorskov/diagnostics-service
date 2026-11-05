<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

/**
 * Сгенерированная история ученика: события по возрастанию occurred_at.
 */
final readonly class EmulatedStream
{
    /**
     * @param list<EmulatedEvent> $events
     */
    public function __construct(
        public string $studentId,
        public string $mentorId,
        public string $pathId,
        public array $events,
    ) {
    }

    /**
     * @return array<string, int> event_type → количество, в порядке первого появления
     */
    public function countsByType(): array
    {
        $counts = [];
        foreach ($this->events as $event) {
            $counts[$event->type] = ($counts[$event->type] ?? 0) + 1;
        }

        return $counts;
    }
}
