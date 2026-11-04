<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Аутентифицирован, но прав нет: чужой ученик или не та роль (ADR 0006, п. 4).
 */
final readonly class JsonAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return new JsonResponse(['error' => 'forbidden'], Response::HTTP_FORBIDDEN);
    }
}
