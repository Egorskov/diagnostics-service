<?php

declare(strict_types=1);

namespace App\Application\Port;

/**
 * Порога нет или значение не разбирается. Это ошибка обработки: дальше работают
 * ретраи и failure transport (ADR 0004, п. 4).
 */
final class InvalidThreshold extends \RuntimeException
{
}
