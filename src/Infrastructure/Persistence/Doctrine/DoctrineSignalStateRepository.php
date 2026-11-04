<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\SignalStateRepository;
use App\Domain\Signal\Audience;
use App\Domain\Signal\SignalKey;
use App\Domain\Signal\SignalState;
use App\Domain\Signal\SignalType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменения сохраняет doctrine_transaction шины: flush здесь не нужен.
 */
final readonly class DoctrineSignalStateRepository implements SignalStateRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function find(SignalKey $key): ?SignalState
    {
        return $this->entityManager->find(SignalState::class, $key->toString());
    }

    public function add(SignalState $state): void
    {
        $this->entityManager->persist($state);
    }

    public function activeEmittedBefore(SignalType $type, Audience $audience, \DateTimeImmutable $before): array
    {
        /** @var list<SignalState> $states */
        $states = $this->entityManager->createQueryBuilder()
            ->select('state')
            ->from(SignalState::class, 'state')
            ->where('state.signalType = :type')
            ->andWhere('state.audience = :audience')
            ->andWhere('state.active = true')
            ->andWhere('state.lastEmittedAt <= :before')
            ->orderBy('state.lastEmittedAt')
            ->setParameter('type', $type->value)
            ->setParameter('audience', $audience->value)
            ->setParameter('before', $before, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();

        return $states;
    }
}
