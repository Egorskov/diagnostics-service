<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Scheduler;

use App\Application\Signal\CheckStudentInactivity;
use App\Application\Signal\CheckWeeklyGoals;
use App\Application\Signal\EscalateStuckTasks;
use App\Infrastructure\Scheduler\DiagnosticsSchedule;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Scheduler\Generator\MessageContext;
use Symfony\Component\Scheduler\RecurringMessage;

final class DiagnosticsScheduleTest extends TestCase
{
    /**
     * @return list<RecurringMessage>
     */
    private static function messages(): array
    {
        return array_values((new DiagnosticsSchedule(new ArrayAdapter()))->getSchedule()->getRecurringMessages());
    }

    public function testThreeHourlyChecks(): void
    {
        $classes = [];
        foreach (self::messages() as $recurring) {
            $context = new MessageContext('default', $recurring->getId(), $recurring->getTrigger(), new \DateTimeImmutable());
            foreach ($recurring->getMessages($context) as $message) {
                $classes[] = $message::class;
            }
        }

        self::assertSame([CheckStudentInactivity::class, EscalateStuckTasks::class, CheckWeeklyGoals::class], $classes);
    }

    #[DataProvider('runs')]
    public function testNextRunIsStartOfNextHourInBelgrade(string $now, string $expected): void
    {
        foreach (self::messages() as $recurring) {
            $next = $recurring->getTrigger()->getNextRunDate(new \DateTimeImmutable($now));

            self::assertSame($expected, $next?->setTimezone(new \DateTimeZone(DiagnosticsSchedule::ZONE))->format('Y-m-d H:i:s'));
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function runs(): iterable
    {
        yield 'середина часа' => ['2026-11-05T16:37:00Z', '2026-11-05 18:00:00'];
        yield 'ровно начало часа' => ['2026-11-05T17:00:00Z', '2026-11-05 19:00:00'];
        yield 'летнее время' => ['2026-07-02T15:59:59Z', '2026-07-02 18:00:00'];
    }
}
