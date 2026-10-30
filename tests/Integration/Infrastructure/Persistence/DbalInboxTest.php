<?php

declare(strict_types=1);

namespace App\Tests\Integration\Infrastructure\Persistence;

use App\Application\Event\EventMetadata;
use App\Infrastructure\Persistence\Doctrine\DbalInbox;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class DbalInboxTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        $this->connection->executeStatement('DELETE FROM inbox_event');
    }

    private static function metadata(string $eventId): EventMetadata
    {
        return new EventMetadata($eventId, 'task.completed', 1, new \DateTimeImmutable('2026-10-30T09:00:00Z'), 'courses');
    }

    public function testMarksEventOnlyOnce(): void
    {
        $inbox = new DbalInbox($this->connection, new MockClock('2026-10-30 12:00:00', 'Europe/Belgrade'));
        $metadata = self::metadata('0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f');

        self::assertTrue($inbox->markProcessed($metadata));
        self::assertFalse($inbox->markProcessed($metadata));

        $rows = $this->connection->fetchAllAssociative('SELECT event_id, event_type, received_at FROM inbox_event');
        self::assertSame([[
            'event_id' => '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f',
            'event_type' => 'task.completed',
            // время из часов, приведённое к UTC: 12:00 Белград = 11:00 UTC
            'received_at' => '2026-10-30 11:00:00',
        ]], $rows);
    }

    public function testDifferentEventsAreIndependent(): void
    {
        $inbox = new DbalInbox($this->connection, new MockClock());

        self::assertTrue($inbox->markProcessed(self::metadata('0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f')));
        self::assertTrue($inbox->markProcessed(self::metadata('1c7f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f')));
    }
}
