<?php

declare(strict_types=1);

namespace App\UI\Http\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * ЗАГЛУШКА MVP: читает sub, role и exp из payload JWT и НЕ проверяет подпись (ADR 0006, п. 2).
 * Любой может представиться кем угодно — API нельзя выставлять за пределы доверенной сети.
 */
final class StubJwtAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * Каждый запрос firewall'а: без токена — тоже, чтобы ответить 401, а не пропустить анонима.
     */
    public function supports(Request $request): bool
    {
        return true;
    }

    public function authenticate(Request $request): Passport
    {
        $user = $this->userFrom((string) $request->headers->get('Authorization'));

        return new SelfValidatingPassport(new UserBadge($user->id, static fn (): ApiUser => $user));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return self::unauthorized();
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return self::unauthorized();
    }

    private function userFrom(string $header): ApiUser
    {
        if (1 !== preg_match('/^Bearer ([^.\s]+)\.([^.\s]+)\.([^.\s]*)$/', $header, $parts)) {
            throw new BadCredentialsException('Expected "Authorization: Bearer header.payload.signature".');
        }
        // header и signature не проверяются: заглушка (ADR 0006, п. 2)
        $payload = strtr($parts[2], '-_', '+/');
        $json = base64_decode(str_pad($payload, \strlen($payload) + (4 - \strlen($payload) % 4) % 4, '='), true);
        $claims = false === $json ? null : json_decode($json, true);
        if (!\is_array($claims)) {
            throw new BadCredentialsException('Token payload is not a JSON object.');
        }

        $sub = $claims['sub'] ?? null;
        $role = \is_string($claims['role'] ?? null) ? ApiRole::tryFrom($claims['role']) : null;
        if (!\is_string($sub) || '' === $sub || null === $role) {
            throw new BadCredentialsException('Token must carry "sub" and "role" (student or mentor).');
        }
        $exp = $claims['exp'] ?? null;
        if (null !== $exp && (!\is_int($exp) || $exp <= $this->clock->now()->getTimestamp())) {
            throw new BadCredentialsException('Token has expired.');
        }

        return new ApiUser($sub, $role);
    }

    private static function unauthorized(): JsonResponse
    {
        return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
    }
}
