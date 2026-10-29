<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Проверяет, что окружение собрано: контейнер компилируется, база доступна,
 * время в приложении — UTC.
 */
final class KernelSmokeTest extends KernelTestCase
{
    public function testClockIsUtc(): void
    {
        self::bootKernel();

        $now = self::getContainer()->get(ClockInterface::class)->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    public function testDatabaseIsReachable(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);

        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'));
    }
}
