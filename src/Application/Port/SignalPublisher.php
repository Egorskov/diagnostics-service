<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Signal\OutgoingSignal;

/**
 * Публикация сигнала в exchange diagnostics.events; вызывается только relay, вне транзакции изменений.
 */
interface SignalPublisher
{
    public function publish(OutgoingSignal $signal): void;
}
