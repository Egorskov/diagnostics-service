<?php

declare(strict_types=1);

namespace App\Tests\Unit\UI\Http\Security;

use App\Tests\Support\ApiToken;
use App\UI\Http\Security\ApiRole;
use App\UI\Http\Security\ApiUser;
use App\UI\Http\Security\StubJwtAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class StubJwtAuthenticatorTest extends TestCase
{
    private const NOW = '2026-11-04T12:00:00Z';

    private static function authenticator(): StubJwtAuthenticator
    {
        return new StubJwtAuthenticator(new MockClock(self::NOW));
    }

    private static function request(?string $authorization): Request
    {
        $request = Request::create('/api/v1/mentor/students');
        if (null !== $authorization) {
            $request->headers->set('Authorization', $authorization);
        }

        return $request;
    }

    /**
     * @param non-empty-string $id
     */
    #[DataProvider('validTokens')]
    public function testAuthenticates(string $token, string $id, ApiRole $role): void
    {
        $user = self::authenticator()->authenticate(self::request('Bearer '.$token))->getUser();

        self::assertEquals(new ApiUser($id, $role), $user);
        self::assertSame(['ROLE_'.strtoupper($role->value)], $user->getRoles());
    }

    /**
     * @return iterable<string, array{string, non-empty-string, ApiRole}>
     */
    public static function validTokens(): iterable
    {
        yield 'ученик без exp' => [ApiToken::student('s1'), 's1', ApiRole::Student];
        yield 'ментор' => [ApiToken::mentor('m1'), 'm1', ApiRole::Mentor];
        yield 'exp в будущем' => [ApiToken::withClaims(['sub' => 's1', 'role' => 'student', 'exp' => (new \DateTimeImmutable(self::NOW))->getTimestamp() + 1]), 's1', ApiRole::Student];
        yield 'произвольная подпись' => [ApiToken::withClaims(['sub' => 's1', 'role' => 'student'], 'garbage'), 's1', ApiRole::Student];
        yield 'пустая подпись' => [ApiToken::withClaims(['sub' => 's1', 'role' => 'student'], ''), 's1', ApiRole::Student];
    }

    #[DataProvider('invalidHeaders')]
    public function testRejects(?string $authorization): void
    {
        $this->expectException(AuthenticationException::class);

        self::authenticator()->authenticate(self::request($authorization));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidHeaders(): iterable
    {
        $now = (new \DateTimeImmutable(self::NOW))->getTimestamp();

        yield 'нет заголовка' => [null];
        yield 'не Bearer' => ['Basic czE6cGFzcw=='];
        yield 'две части' => ['Bearer abc.def'];
        yield 'payload не base64' => ['Bearer abc.!!!.sig'];
        yield 'payload не JSON' => ['Bearer abc.'.rtrim(base64_encode('not json'), '=').'.sig'];
        yield 'payload — JSON-строка' => ['Bearer abc.'.rtrim(base64_encode('"s1"'), '=').'.sig'];
        yield 'нет sub' => ['Bearer '.ApiToken::withClaims(['role' => 'student'])];
        yield 'пустой sub' => ['Bearer '.ApiToken::withClaims(['sub' => '', 'role' => 'student'])];
        yield 'sub — число' => ['Bearer '.ApiToken::withClaims(['sub' => 42, 'role' => 'student'])];
        yield 'роль admin' => ['Bearer '.ApiToken::withClaims(['sub' => 's1', 'role' => 'admin'])];
        yield 'нет роли' => ['Bearer '.ApiToken::withClaims(['sub' => 's1'])];
        yield 'истёк ровно сейчас' => ['Bearer '.ApiToken::withClaims(['sub' => 's1', 'role' => 'student', 'exp' => $now])];
        yield 'exp — строка' => ['Bearer '.ApiToken::withClaims(['sub' => 's1', 'role' => 'student', 'exp' => 'tomorrow'])];
    }

    public function testFailureAndEntryPointAnswer401(): void
    {
        $authenticator = self::authenticator();

        foreach ([$authenticator->start(self::request(null)), $authenticator->onAuthenticationFailure(self::request(null), new AuthenticationException())] as $response) {
            self::assertSame(401, $response->getStatusCode());
            self::assertSame('{"error":"unauthorized"}', $response->getContent());
        }
    }
}
