<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Пользователь Read-API из claims токена: sub и role (ADR 0006, п. 3).
 */
final readonly class ApiUser implements UserInterface
{
    /**
     * @param non-empty-string $id
     */
    public function __construct(
        public string $id,
        public ApiRole $role,
    ) {
    }

    public function getRoles(): array
    {
        return [$this->role->securityRole()];
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    // секретов в пользователе нет
    #[\Deprecated(since: 'symfony/security-core 7.3')]
    public function eraseCredentials(): void
    {
    }
}
