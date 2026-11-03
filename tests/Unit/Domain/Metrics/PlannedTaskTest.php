<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Metrics;

use App\Domain\Metrics\PlannedTask;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlannedTaskTest extends TestCase
{
    public function testKeepsValues(): void
    {
        $completedAt = new \DateTimeImmutable('2026-10-30T12:00:00Z');

        $task = new PlannedTask(60, $completedAt, 45);

        self::assertSame(60, $task->estimateMinutes);
        self::assertSame($completedAt, $task->completedAt);
        self::assertSame(45, $task->loggedMinutes);
        self::assertTrue($task->isCompleted());
        self::assertFalse((new PlannedTask(1, null, 0))->isCompleted());
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalidValues(int $estimateMinutes, int $loggedMinutes): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PlannedTask($estimateMinutes, null, $loggedMinutes);
    }

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalid(): iterable
    {
        yield 'нулевая оценка' => [0, 0];
        yield 'отрицательная оценка' => [-5, 0];
        yield 'отрицательное списание' => [60, -1];
    }
}
