<?php

declare(strict_types=1);

namespace App\UI\Http\Controller;

use App\Application\Api\MentorStudentsQuery;
use App\UI\Http\Security\ApiUser;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final readonly class MentorStudentsController
{
    public function __construct(private MentorStudentsQuery $query)
    {
    }

    #[Route('/api/v1/mentor/students', name: 'api_mentor_students', methods: ['GET'])]
    #[IsGranted('ROLE_MENTOR')]
    public function __invoke(#[CurrentUser] ApiUser $mentor): JsonResponse
    {
        return SummaryJson::response([
            'students' => array_map(SummaryJson::mentorStudent(...), $this->query->list($mentor->id)),
        ]);
    }
}
