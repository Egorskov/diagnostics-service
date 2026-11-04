<?php

declare(strict_types=1);

namespace App\Domain\Signal;

enum Audience: string
{
    case Student = 'student';
    case Mentor = 'mentor';
}
