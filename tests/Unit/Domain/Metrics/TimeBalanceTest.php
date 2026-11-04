<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Metrics;

use App\Domain\Metrics\TimeBalance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimeBalanceTest extends TestCase
{
    /**
     * @param array<'theory'|'practice'|'interview', int> $minutes
     * @param array{float, float, float}                  $expected theory, practice, interview
     */
    #[DataProvider('cases')]
    public function testShares(array $minutes, array $expected): void
    {
        $balance = TimeBalance::of($minutes);

        self::assertEqualsWithDelta($expected, [$balance->theory, $balance->practice, $balance->interview], 1e-9);
    }

    /**
     * @return iterable<string, array{array<'theory'|'practice'|'interview', int>, array{float, float, float}}>
     */
    public static function cases(): iterable
    {
        yield 'пример SPEC §8' => [['practice' => 450, 'interview' => 90], [0.0, 450 / 540, 90 / 540]];
        yield 'одна категория' => [['theory' => 120], [1.0, 0.0, 0.0]];
        yield 'ничего не списано' => [[], [0.0, 0.0, 0.0]];
        yield 'нулевые минуты' => [['theory' => 0, 'practice' => 0], [0.0, 0.0, 0.0]];
    }
}
