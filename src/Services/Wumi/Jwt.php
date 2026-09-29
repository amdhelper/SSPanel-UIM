<?php

declare(strict_types=1);

namespace App\Services\Wumi;

use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function hash_equals;
use function hash_hmac;
use function is_array;
use function json_decode;
use function json_encode;
use function rtrim;
use function strtr;
use function time;

/**
 * 极简 HS256 JWT 实现。
 *
 * 与 wumi Go 后端 (github.com/golang-jwt/jwt, jwt.SigningMethodHS256) 互通：
 * claims 形如 {"sub": <public_key>, "exp": ..., "iat": ..., "typ": "access"}，
 * 密钥即 wumi 的 JWT_SECRET。
 *
 * 刻意不依赖 vendor/，以便在独立脚本/单测（无 composer）中直接验证。
 */
final class Jwt
{
    /**
     * 校验并解码 token；失败返回 null。
     *
     * @param string $expectedTyp 期望的 typ（默认 access；传空串则不校验）
     *
     * @return array<string, mixed>|null
     */
    public static function decode(string $token, string $secret, string $expectedTyp = 'access'): ?array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSig] = $parts;

        $header = json_decode(self::b64urlDecode($encodedHeader), true);
        $payload = json_decode(self::b64urlDecode($encodedPayload), true);

        if (! is_array($header) || ! is_array($payload)) {
            return null;
        }

        if (($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        $signature = self::b64urlDecode($encodedSig);
        $expected = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);

        if (! hash_equals($expected, $signature)) {
            return null;
        }

        if (isset($payload['exp']) && (int) $payload['exp'] <= time()) {
            return null;
        }

        if ($expectedTyp !== '' && ($payload['typ'] ?? '') !== $expectedTyp) {
            return null;
        }

        return $payload;
    }

    /**
     * 签发 HS256 token。
     *
     * @param array<string, mixed> $claims
     */
    public static function encode(array $claims, string $secret): string
    {
        if (! isset($claims['iat'])) {
            $claims['iat'] = time();
        }

        $header = self::b64urlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64urlEncode(json_encode($claims));
        $sig = self::b64urlEncode(hash_hmac('sha256', $header . '.' . $payload, $secret, true));

        return $header . '.' . $payload . '.' . $sig;
    }

    private static function b64urlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    private static function b64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}