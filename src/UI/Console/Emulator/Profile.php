<?php

declare(strict_types=1);

namespace App\UI\Console\Emulator;

/**
 * Поведение эмулируемого ученика; каждый профиль, кроме steady, доводит до одного сигнала SPEC §7.2.
 */
enum Profile: string
{
    case Steady = 'steady';
    case Behind = 'behind';
    case Stuck = 'stuck';
    case Inactive = 'inactive';
}
