<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use App\Application\Port\ProgressReadModel;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Ученик видит только себя, ментор — учеников, чей текущий путь ведёт он (SPEC §8; design, решение 3).
 *
 * @extends Voter<string, string>
 */
final class StudentVoter extends Voter
{
    public const VIEW = 'VIEW_STUDENT';

    public function __construct(private readonly ProgressReadModel $readModel)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::VIEW === $attribute && \is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof ApiUser) {
            return false;
        }

        return match ($user->role) {
            ApiRole::Student => $user->id === $subject,
            // ученик без пути — без ментора: 403, чтобы ментор не узнавал о чужих учениках
            ApiRole::Mentor => $this->readModel->currentPath($subject)?->mentorId === $user->id,
        };
    }
}
