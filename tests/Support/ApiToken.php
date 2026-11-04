<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * JWT для заглушки Read-API: base64url от JSON и произвольная подпись (ADR 0006, «Последствия»).
 */
final class ApiToken
{
    /**
     * @param array<string, mixed> $claims
     */
    public static function withClaims(array $claims, string $signature = 'not-checked'): string
    {
        return self::encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.self::encode($claims).'.'.$signature;
    }

    public static function student(string $id): string
    {
        return self::withClaims(['sub' => $id, 'role' => 'student']);
    }

    public static function mentor(string $id): string
    {
        return self::withClaims(['sub' => $id, 'role' => 'mentor']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, \JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}
