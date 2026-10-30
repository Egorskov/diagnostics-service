<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Messenger;

use App\Infrastructure\Messenger\DecodeFailure;
use App\Infrastructure\Messenger\UndecodableMessage;
use App\Infrastructure\Messenger\UndecodableMessageHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class UndecodableMessageHandlerTest extends TestCase
{
    public function testFailsWithoutRetryAndKeepsReason(): void
    {
        $this->expectException(UnrecoverableMessageHandlingException::class);
        $this->expectExceptionMessage('Undecodable message (unknown_event_type): Unknown event type "task.complete"');

        (new UndecodableMessageHandler())(new UndecodableMessage('{}', DecodeFailure::UnknownEventType, 'Unknown event type "task.complete"'));
    }
}
