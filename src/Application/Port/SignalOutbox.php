<?php

declare(strict_types=1);

namespace App\Application\Port;

use App\Application\Signal\OutgoingSignal;

/**
 * Запись сигнала в транзакции вызывающего кода (SPEC §9, ADR 0005, п. 3). В брокер сигнал уходит позже, через relay.
 */
interface SignalOutbox
{
    public function put(OutgoingSignal $signal): void;
}
