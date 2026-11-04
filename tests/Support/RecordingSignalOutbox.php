<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Port\SignalOutbox;
use App\Application\Signal\OutgoingSignal;

final class RecordingSignalOutbox implements SignalOutbox
{
    /** @var list<OutgoingSignal> */
    public array $signals = [];

    public function put(OutgoingSignal $signal): void
    {
        $this->signals[] = $signal;
    }
}
