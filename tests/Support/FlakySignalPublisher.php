<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\SignalPublisher;
use App\Application\Signal\OutgoingSignal;

final class FlakySignalPublisher implements SignalPublisher
{
    /** Сколько ближайших публикаций упадёт */
    public static int $failures = 0;

    public function __construct(private readonly SignalPublisher $inner)
    {
    }

    public function publish(OutgoingSignal $signal): void
    {
        if (self::$failures > 0) {
            --self::$failures;

            throw new \RuntimeException('Broker is unavailable');
        }

        $this->inner->publish($signal);
    }
}
