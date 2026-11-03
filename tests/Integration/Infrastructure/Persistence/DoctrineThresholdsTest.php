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
        $this->thresholds = new DoctrineThresholds(self::getContainer()->get(EntityManagerInterface::class));
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();
        parent::tearDown();
    }

    private function setIdleTimeout(string $value): void
    {
        $this->connection->update('threshold', ['value' => $value], ['key' => 'idle_timeout_min']);
    }

    public function testInitialValueComesFromMigration(): void
    {
        self::assertSame(15, $this->thresholds->idleTimeoutMinutes());
    }

    public function testReadsChangedNumber(): void
    {
        $this->setIdleTimeout('30');

        self::assertSame(30, $this->thresholds->idleTimeoutMinutes());
    }

    public function testMissingThresholdIsError(): void
    {
        $this->connection->delete('threshold', ['key' => 'idle_timeout_min']);

        $this->expectException(InvalidThreshold::class);

        $this->thresholds->idleTimeoutMinutes();
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValueIsError(string $value): void
    {
        $this->setIdleTimeout($value);

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

    public function testSignalThresholdsComeFromMigration(): void
    {
        self::assertSame(3, $this->thresholds->inactiveStudentDays());
        self::assertSame(7, $this->thresholds->inactiveMentorDays());
        self::assertSame(1.5, $this->thresholds->stuckTimeRatio());
        self::assertSame(10, $this->thresholds->stuckFailedRuns());
        self::assertSame(24, $this->thresholds->stuckEscalationHours());
        self::assertSame(0.5, $this->thresholds->weeklyGoalMinRatio());

        $check = $this->thresholds->weeklyGoalCheck();
        self::assertSame([4, 18, 0, 'Europe/Belgrade'], [$check->isoDay, $check->hour, $check->minute, $check->zone->getName()]);
    }

    public function testReadsChangedWeeklyCheck(): void
    {
        $this->connection->update('threshold', ['value' => 'Пятница 09:30 UTC'], ['key' => 'weekly_goal_check']);

        $check = $this->thresholds->weeklyGoalCheck();

        self::assertSame([5, 9, 30, 'UTC'], [$check->isoDay, $check->hour, $check->minute, $check->zone->getName()]);
    }

    #[DataProvider('invalidSignalValues')]
    public function testInvalidSignalThresholdIsError(string $key, string $value, callable $read): void
    {
        $this->connection->update('threshold', ['value' => $value], ['key' => $key]);

        $this->expectException(InvalidThreshold::class);

        $read($this->thresholds);
    }

    /**
     * @return iterable<string, array{string, string, callable(DoctrineThresholds): mixed}>
     */
    public static function invalidSignalValues(): iterable
    {
        yield 'доля — текст' => ['stuck_time_ratio', 'полторы', static fn (DoctrineThresholds $t) => $t->stuckTimeRatio()];
        yield 'доля — ноль' => ['weekly_goal_min_ratio', '0', static fn (DoctrineThresholds $t) => $t->weeklyGoalMinRatio()];
        yield 'дни — дробные' => ['inactive_student_days', '2.5', static fn (DoctrineThresholds $t) => $t->inactiveStudentDays()];
        yield 'неизвестный день' => ['weekly_goal_check', 'четвер 18:00 Europe/Belgrade', static fn (DoctrineThresholds $t) => $t->weeklyGoalCheck()];
        yield 'нет зоны' => ['weekly_goal_check', 'четверг 18:00', static fn (DoctrineThresholds $t) => $t->weeklyGoalCheck()];
        yield 'неизвестная зона' => ['weekly_goal_check', 'четверг 18:00 Europe/Atlantis', static fn (DoctrineThresholds $t) => $t->weeklyGoalCheck()];
        yield 'час 25' => ['weekly_goal_check', 'четверг 25:00 Europe/Belgrade', static fn (DoctrineThresholds $t) => $t->weeklyGoalCheck()];
    }

    public function testMissingSignalThresholdIsError(): void
    {
        $this->connection->delete('threshold', ['key' => 'stuck_failed_runs']);

        $this->expectException(InvalidThreshold::class);

        $this->thresholds->stuckFailedRuns();
    }
}
