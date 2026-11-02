<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Infrastructure\Messenger\DecodeFailure;
use App\Infrastructure\Messenger\UndecodableMessage;
use App\Tests\Support\EventFlowTestCase;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Приём событий (event-ingestion); реальный AMQP проверяется вручную.
 */
final class EventIngestionTest extends EventFlowTestCase
{
    private const EVENT_ID = '0b6f6a7e-3c1d-4b8a-9e2f-5a4c3b2d1e0f';

    private static function taskCompleted(): string
    {
        return self::event(self::EVENT_ID, 'task.completed', [
            'student_id' => '11111111-1111-4111-8111-111111111111',
            'path_id' => '22222222-2222-4222-8222-222222222222',
            'task_id' => '33333333-3333-4333-8333-333333333333',
        ]);
    }

    public function testDuplicateDeliveryIsHandledOnce(): void
    {
        self::deliver('courses', self::taskCompleted());
        self::deliver('courses', self::taskCompleted());

        self::consume(2);

        self::assertSame(1, $this->rowCount('task_progress'));
        self::assertSame('completed', $this->connection->fetchOne('SELECT status FROM task_progress'));
        self::assertCount(2, self::transport('courses')->getAcknowledged());
        self::assertSame([], self::transport('failed')->getSent());
        self::assertSame(1, $this->rowCount('inbox_event'));
    }

    public function testInvalidMessageGoesToFailureTransportWithoutRetries(): void
    {
        self::deliver('courses', '{"event_type": "task.completed"');

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
        self::assertSame(0, $this->rowCount('task_progress'));
    }
}
