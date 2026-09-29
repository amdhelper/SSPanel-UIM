<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Services\Wumi\Jwt;
use Tests\TestCase;
use function base64_encode;
use function explode;
use function json_encode;
use function rtrim;
use function strtr;
use function time;

/**
 * wumi 身份桥回归测试（无 DB / 无 vendor 依赖）。
 *
 * 关键不变量：本实现必须与 wumi Go 后端 (golang-jwt/jwt/v5, HS256) 互通——
 * claims 为 {sub, exp, iat, typ}，密钥为共享 JWT_SECRET。
 */
final class WumiIdentityTest extends TestCase
{
    private const SECRET = 'test-jwt-secret-abcdef';

    /** 真实 wumi 公钥形态（base64，含 / 与 = ） */
    private const PUBLIC_KEY = 'wXaBI/0eTtxGpd2baIRFO9J3lsBd3kxdNNCy1WU3KLI=';

    public function testAccessTokenRoundTripKeepsBase64PublicKey(): void
    {
        $token = Jwt::encode([
            'sub' => self::PUBLIC_KEY,
            'typ' => 'access',
            'exp' => time() + 3600,
            'iat' => time(),
        ], self::SECRET);

        $claims = Jwt::decode($token, self::SECRET, 'access');

        self::assertIsArray($claims);
        self::assertSame(self::PUBLIC_KEY, $claims['sub']);
    }

    public function testRejectsWrongSecretAndWrongTyp(): void
    {
        $token = Jwt::encode([
            'sub' => self::PUBLIC_KEY,
            'typ' => 'access',
            'exp' => time() + 3600,
        ], self::SECRET);

        self::assertNull(Jwt::decode($token, 'wrong-secret', 'access'));
        self::assertNull(Jwt::decode($token, self::SECRET, 'refresh'));
    }

    public function testRejectsExpiredToken(): void
    {
        $token = Jwt::encode([
            'sub' => self::PUBLIC_KEY,
            'typ' => 'access',
            'exp' => time() - 10,
            'iat' => time() - 100,
        ], self::SECRET);

        self::assertNull(Jwt::decode($token, self::SECRET, 'access'));
    }

    public function testRejectsTamperedPayload(): void
    {
        $token = Jwt::encode([
            'sub' => self::PUBLIC_KEY,
            'typ' => 'access',
            'exp' => time() + 3600,
        ], self::SECRET);

        $parts = explode('.', $token);
        $forged = rtrim(strtr(base64_encode(json_encode([
            'sub' => 'attacker',
            'typ' => 'access',
            'exp' => time() + 3600,
        ])), '+/', '-_'), '=');

        $tampered = $parts[0] . '.' . $forged . '.' . $parts[2];

        self::assertNull(Jwt::decode($tampered, self::SECRET, 'access'));
    }

    public function testRejectsMalformedTokens(): void
    {
        self::assertNull(Jwt::decode('abc.def', self::SECRET, 'access'));
        self::assertNull(Jwt::decode('', self::SECRET, 'access'));
    }
}