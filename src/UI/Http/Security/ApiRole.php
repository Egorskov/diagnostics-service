<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

/**
 * Значение claim role (ADR 0006, п. 1).
 */
enum ApiRole: string
{
    case Student = 'student';
    case Mentor = 'mentor';

    public function securityRole(): string
    {
        return 'ROLE_'.strtoupper($this->value);
    }
}
