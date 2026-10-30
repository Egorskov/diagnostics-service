<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Infrastructure\Messenger\DecodeFailure;
use App\Infrastructure\Messenger\EnvelopeSerializer;
use App\Infrastructure\Messenger\UndecodableMessage;
use App\Tests\Support\RecordingEventHandler;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Вся цепочка приёма: транспорт → EnvelopeSerializer → doctrine_transaction → inbox → хендлер,
 * через настоящий воркер messenger:consume. Брокер заменён in-memory транспортом
 * (config/packages/messenger.yaml, when@test); реальный AMQP проверяется вручную (задача 7.2).
 */
final class EventIngestionTest extends KernelTestCase
{
    private const EVENT_ID = '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f';

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM inbox_event');
    }

    private static function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.'.$name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Сырое тело из брокера → EnvelopeSerializer::decode() → транспорт, как это делает AMQP-receiver.
     */
    private static function deliver(string $body): void
    {
        $serializer = self::getContainer()->get(EnvelopeSerializer::class);
        self::transport('courses')->send($serializer->decode(['body' => $body, 'headers' => []]));
    }

    private static function consume(int $messages): void
    {
        $application = new Application(self::$kernel ?? throw new \LogicException('Kernel is not booted'));
        $tester = new CommandTester($application->find('messenger:consume'));
        $tester->execute(['receivers' => ['courses'], '--limit' => $messages, '--time-limit' => 5, '--no-reset' => true]);
    }

    private static function taskCompleted(): string
    {
        return json_encode([
            'event_id' => self::EVENT_ID,
            'event_type' => 'task.completed',
            'event_version' => 1,
            'occurred_at' => '2026-10-30T12:00:00Z',
            'producer' => 'courses',
            'payload' => [
                'student_id' => '11111111-1111-4111-8111-111111111111',
                'path_id' => '22222222-2222-4222-8222-222222222222',
                'task_id' => '33333333-3333-4333-8333-333333333333',
            ],
        ], \JSON_THROW_ON_ERROR);
    }

    public function testDuplicateDeliveryIsHandledOnce(): void
    {
        self::deliver(self::taskCompleted());
        self::deliver(self::taskCompleted());

        self::consume(2);

        $handler = self::getContainer()->get(RecordingEventHandler::class);
        self::assertCount(1, $handler->handled);
        self::assertSame(self::EVENT_ID, $handler->handled[0]->metadata()->eventId);
        self::assertCount(2, self::transport('courses')->getAcknowledged());
        self::assertSame([], self::transport('failed')->getSent());
        self::assertSame(1, (int) self::getContainer()->get(Connection::class)->fetchOne('SELECT count(*) FROM inbox_event'));
    }

    public function testInvalidMessageGoesToFailureTransportWithoutRetries(): void
    {
        self::deliver('{"event_type": "task.completed"');

        self::consume(1);

        $failed = self::transport('failed')->getSent();
        self::assertCount(1, $failed);
        $message = $failed[0]->getMessage();
        self::assertInstanceOf(UndecodableMessage::class, $message);
        self::assertSame(DecodeFailure::InvalidJson, $message->reason);
        self::assertSame('{"event_type": "task.completed"', $message->rawBody);
        // ретраев не было: счётчик 0 (стамп с нулём ставит сам переход в failure transport),
        // и в courses сообщение повторно не отправлялось
        self::assertSame(0, $failed[0]->last(RedeliveryStamp::class)?->getRetryCount());
        self::assertCount(1, self::transport('courses')->getSent());
        self::assertSame([], self::getContainer()->get(RecordingEventHandler::class)->handled);
    }
}
