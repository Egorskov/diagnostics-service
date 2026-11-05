<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * app:emulate-events: опции, NDJSON, отчёт; транспорты — in-memory тестового окружения.
 */
final class EmulateEventsCommandTest extends KernelTestCase
{
    use ClockSensitiveTrait;

    private const STUDENT = '11111111-1111-4111-8111-111111111111';

    /**
     * @param array<string, mixed> $options
     */
    private static function emulate(array $options): CommandTester
    {
        $tester = new CommandTester((new Application(self::bootKernel()))->find('app:emulate-events'));
        self::mockTime(new \DateTimeImmutable('2026-11-04T17:12:00Z'));
        $tester->execute($options);

        return $tester;
    }

    /**
     * @return array<string, int>
     */
    private static function sent(): array
    {
        $counts = [];
        foreach (['courses', 'time', 'heartbeat'] as $name) {
            $transport = self::getContainer()->get('messenger.transport.'.$name);
            self::assertInstanceOf(InMemoryTransport::class, $transport);
            $counts[$name] = \count($transport->getSent());
        }

        return $counts;
    }

    public function testDryRunPrintsEnvelopesAndPublishesNothing(): void
    {
        $tester = self::emulate(['--dry-run' => true, '--seed' => '42', '--days' => '3']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $lines = explode("\n", trim($tester->getDisplay()));
        self::assertGreaterThan(1, \count($lines));
        foreach ($lines as $line) {
            $envelope = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($envelope);
            self::assertSame('emulator', $envelope['producer']);
        }
        self::assertSame('path.assigned', json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR)['event_type']);
        self::assertSame(['courses' => 0, 'time' => 0, 'heartbeat' => 0], self::sent());
    }

    public function testSameSeedGivesSameOutput(): void
    {
        $first = self::emulate(['--dry-run' => true, '--seed' => '42'])->getDisplay();
        self::ensureKernelShutdown();
        $second = self::emulate(['--dry-run' => true, '--seed' => '42'])->getDisplay();

        self::assertSame($first, $second);
    }

    public function testPublishesToTransportsAndReports(): void
    {
        $dryRun = self::emulate(['--dry-run' => true, '--seed' => '7', '--student' => self::STUDENT, '--days' => '2'])->getDisplay();
        self::ensureKernelShutdown();
        $types = array_count_values(array_map(static fn (string $line): string => json_decode($line, true, flags: \JSON_THROW_ON_ERROR)['event_type'], explode("\n", trim($dryRun))));

        $tester = self::emulate(['--seed' => '7', '--student' => self::STUDENT, '--days' => '2']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertSame([
            'courses' => ($types['path.assigned'] ?? 0) + ($types['task.started'] ?? 0) + ($types['task.completed'] ?? 0) + ($types['sandbox.run'] ?? 0),
            'time' => $types['time.logged'],
            'heartbeat' => $types['activity.heartbeat'],
        ], self::sent());
        $display = $tester->getDisplay();
        self::assertStringContainsString(self::STUDENT, $display);
        self::assertStringContainsString('Seed', $display);
        self::assertStringContainsString('/api/v1/students/'.self::STUDENT.'/summary', $display);
        self::assertMatchesRegularExpression('/Bearer [\w-]+\.[\w-]+\.emulator/', $display);
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function invalid(): iterable
    {
        yield 'неизвестный профиль' => [['profile' => 'lazy'], 'Unknown profile'];
        yield 'ученик не UUID' => [['--student' => 'not-a-uuid'], '--student must be a UUID'];
        yield 'ментор не UUID' => [['--mentor' => '42'], '--mentor must be a UUID'];
        yield 'ноль дней' => [['--days' => '0'], '--days must be between 1 and 56'];
        yield 'дни не число' => [['--days' => 'week'], '--days must be a non-negative integer'];
        yield 'короткая история неактивности' => [['profile' => 'inactive', '--days' => '2'], 'at least 5'];
    }

    /**
     * @param array<string, string> $options
     */
    #[DataProvider('invalid')]
    public function testInvalidOptionsPublishNothing(array $options, string $message): void
    {
        $tester = self::emulate($options);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString($message, preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
        self::assertSame(['courses' => 0, 'time' => 0, 'heartbeat' => 0], self::sent());
    }
}
