<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Signal\OutgoingSignal;
use App\Infrastructure\Messenger\EnvelopeSerializer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Bridge\Amqp\Transport\AmqpStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Вся цепочка приёма: транспорт → EnvelopeSerializer → doctrine_transaction → inbox → хендлер,
 * через настоящий воркер messenger:consume. Брокер заменён in-memory транспортом
 * (config/packages/messenger.yaml, when@test).
 */
abstract class EventFlowTestCase extends KernelTestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get(Connection::class);
        foreach (['inbox_event', 'student_path', 'path_task', 'task_progress', 'time_log', 'sandbox_run', 'activity_session', 'signal_state'] as $table) {
            $this->connection->executeStatement('DELETE FROM '.$table);
        }
        $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'outbox'");
        FlakySignalPublisher::$failures = 0;
    }

    protected static function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Сырое тело из брокера → EnvelopeSerializer::decode() → транспорт, как это делает AMQP-receiver.
     */
    protected static function deliver(string $transport, string $body): void
    {
        $serializer = self::getContainer()->get(EnvelopeSerializer::class);
        self::transport($transport)->send($serializer->decode(['body' => $body, 'headers' => []]));
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function event(string $eventId, string $eventType, array $payload, string $occurredAt = '2026-10-30T12:00:00Z'): string
    {
        return json_encode([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'event_version' => 1,
            'occurred_at' => $occurredAt,
            'producer' => 'courses',
            'payload' => $payload,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * @param list<string> $receivers
     */
    protected static function consume(int $messages, array $receivers = ['courses']): void
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Kernel is not booted'));
        $tester = new CommandTester($application->find('messenger:consume'));
        $tester->execute(['receivers' => $receivers, '--limit' => $messages, '--time-limit' => 5, '--no-reset' => true]);
    }

    /**
     * Сигналы, которые relay отправил в exchange diagnostics.events.
     *
     * @return list<OutgoingSignal>
     */
    protected static function publishedSignals(): array
    {
        return array_values(array_map(static function (Envelope $envelope): OutgoingSignal {
            $signal = $envelope->getMessage();
            self::assertInstanceOf(OutgoingSignal::class, $signal);
            self::assertSame($signal->type->value, $envelope->last(AmqpStamp::class)?->getRoutingKey());

            return $signal;
        }, self::transport('signals')->getSent()));
    }

    protected function outboxCount(): int
    {
        return (int) $this->connection->fetchOne("SELECT count(*) FROM messenger_messages WHERE queue_name = 'outbox'");
    }

    protected function rowCount(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM '.$table);
    }
}
