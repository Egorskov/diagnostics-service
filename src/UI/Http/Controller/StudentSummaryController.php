<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Api\StudentSummaryQuery;
use App\UI\Http\Security\StudentVoter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class StudentSummaryController
{
    public function __construct(private StudentSummaryQuery $query)
    {
    }

    /**
     * Права — раньше поиска: 401 → 403 → 404 (design, решение 3).
     */
    #[Route('/api/v1/students/{id}/summary', name: 'api_student_summary', methods: ['GET'])]
    #[IsGranted(StudentVoter::VIEW, subject: 'id')]
    public function __invoke(string $id): JsonResponse
    {
        $summary = $this->query->find($id);
        if (null === $summary) {
            return SummaryJson::error('not_found', Response::HTTP_NOT_FOUND);
        }

        return SummaryJson::response(SummaryJson::summary($summary));
    }
}
