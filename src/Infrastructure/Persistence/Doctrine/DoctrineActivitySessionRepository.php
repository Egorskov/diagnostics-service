<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Doctrine;

use App\Application\Port\ActivitySessionRepository;
use App\Domain\Progress\ActivitySession;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Изменения сохраняет doctrine_transaction шины: flush здесь не нужен.
 */
final readonly class DoctrineActivitySessionRepository implements ActivitySessionRepository
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function forTask(string $studentId, string $taskId): array
    {
        return $this->entityManager->getRepository(ActivitySession::class)->findBy(['studentId' => $studentId, 'taskId' => $taskId]);
    }

    public function withoutTaskNear(string $studentId, \DateTimeImmutable $at, int $idleMinutes): array
    {
        $idle = new \DateInterval(\sprintf('PT%dM', $idleMinutes));

        /** @var list<ActivitySession> $sessions */
        $sessions = $this->entityManager->createQueryBuilder()
            ->select('session')
            ->from(ActivitySession::class, 'session')
            ->where('session.studentId = :student')
            ->andWhere('session.taskId IS NULL')
            ->andWhere('session.startedAt <= :latest')
            ->andWhere('session.lastSeenAt >= :earliest')
            ->setParameter('student', $studentId)
            ->setParameter('latest', $at->add($idle), Types::DATETIME_IMMUTABLE)
            ->setParameter('earliest', $at->sub($idle), Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();

        return $sessions;
    }

    public function add(ActivitySession $session): void
    {
        $this->entityManager->persist($session);
    }
}
