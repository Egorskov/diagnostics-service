<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Security;

use App\Application\Port\CurrentPath;
use App\Tests\Support\InMemoryProgressReadModel;
use App\UI\Http\Security\ApiRole;
use App\UI\Http\Security\ApiUser;
use App\UI\Http\Security\StudentVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class StudentVoterTest extends TestCase
{
    private static function voter(): StudentVoter
    {
        $readModel = new InMemoryProgressReadModel();
        $readModel->paths = [
            new CurrentPath('s1', 'old', 'm-old', new \DateTimeImmutable('2026-09-01T00:00:00Z'), 1500),
            new CurrentPath('s1', 'current', 'm1', new \DateTimeImmutable('2026-10-01T00:00:00Z'), 1500),
        ];

        return new StudentVoter($readModel);
    }

    /**
     * @param non-empty-string $id
     */
    private static function token(string $id, ApiRole $role): TokenInterface
    {
        $user = new ApiUser($id, $role);

        return new UsernamePasswordToken($user, 'api', $user->getRoles());
    }

    /**
     * @param non-empty-string $id
     */
    #[DataProvider('cases')]
    public function testVote(string $id, ApiRole $role, string $student, int $expected): void
    {
        self::assertSame($expected, self::voter()->vote(self::token($id, $role), $student, [StudentVoter::VIEW]));
    }

    /**
     * @return iterable<string, array{non-empty-string, ApiRole, string, int}>
     */
    public static function cases(): iterable
    {
        yield 'ученик — себя' => ['s1', ApiRole::Student, 's1', VoterInterface::ACCESS_GRANTED];
        yield 'ученик — себя без пути' => ['s2', ApiRole::Student, 's2', VoterInterface::ACCESS_GRANTED];
        yield 'ученик — чужого' => ['s1', ApiRole::Student, 's2', VoterInterface::ACCESS_DENIED];
        yield 'ментор текущего пути' => ['m1', ApiRole::Mentor, 's1', VoterInterface::ACCESS_GRANTED];
        yield 'ментор прошлого пути' => ['m-old', ApiRole::Mentor, 's1', VoterInterface::ACCESS_DENIED];
        yield 'ментор — ученика без пути' => ['m1', ApiRole::Mentor, 's2', VoterInterface::ACCESS_DENIED];
        yield 'ментор с id ученика' => ['s1', ApiRole::Mentor, 's1', VoterInterface::ACCESS_DENIED];
    }

    public function testAbstainsOnOtherAttributesAndDeniesAnonymous(): void
    {
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, self::voter()->vote(self::token('s1', ApiRole::Student), 's1', ['EDIT']));
        self::assertSame(VoterInterface::ACCESS_DENIED, self::voter()->vote(new NullToken(), 's1', [StudentVoter::VIEW]));
    }
}
