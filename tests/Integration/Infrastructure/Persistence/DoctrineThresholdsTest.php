<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Application\Port\InvalidThreshold;
use App\Infrastructure\Persistence\Doctrine\DoctrineThresholds;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Каждый тест откатывается: таблица порогов общая для всех тестов.
 */
final class DoctrineThresholdsTest extends KernelTestCase
{
    private Connection $connection;
    private DoctrineThresholds $thresholds;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();
        $this->connection->executeStatement("DELETE FROM threshold WHERE key = 'idle_timeout_min'");
        $this->thresholds = new DoctrineThresholds(self::getContainer()->get(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    public function testReadsNumber(): void
    {
        $this->connection->insert('threshold', ['key' => 'idle_timeout_min', 'value' => '30']);

        self::assertSame(30, $this->thresholds->idleTimeoutMinutes());
    }

    public function testMissingThresholdIsError(): void
    {
        $this->expectException(InvalidThreshold::class);

        $this->thresholds->idleTimeoutMinutes();
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValueIsError(string $value): void
    {
        $this->connection->insert('threshold', ['key' => 'idle_timeout_min', 'value' => $value]);

        $this->expectException(InvalidThreshold::class);

        $this->thresholds->idleTimeoutMinutes();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'текст' => ['четверг 18:00'];
        yield 'пусто' => [''];
        yield 'ноль' => ['0'];
        yield 'дробное' => ['1.5'];
    }
}
