<?php

declare(strict_types=1);

namespace App\Services\Wumi;

use App\Models\Config;
use App\Models\User;
use App\Utils\Hash;
use App\Utils\Tools;
use Ramsey\Uuid\Uuid;
use function date;
use function hash;
use function is_array;
use function substr;
use function time;
use function trim;

/**
 * wumi 身份桥。
 *
 * - wumi 是唯一用户体系；SSPanel 通过共享 JWT_SECRET 校验 wumi 下发的 access token，
 *   以 claims.sub（用户公钥）映射到 SSPanel user 行（列 wumi_user_key）。
 * - 首次进入自动开户；之后按公钥复用，不重复建号。
 * - 反向调用 wumi API 时，用同一密钥签发「服务账号」token（sub = wumi_service_key）。
 */
final class Identity
{
    /** wumi access token → 用户公钥（校验失败返回 null） */
    public static function publicKeyFromToken(string $token): ?string
    {
        $secret = self::jwtSecret();
        if ($secret === '') {
            return null;
        }

        $claims = Jwt::decode($token, $secret, 'access');
        if ($claims === null) {
            return null;
        }

        $sub = $claims['sub'] ?? null;

        return is_string($sub) && trim($sub) !== '' ? trim($sub) : null;
    }

    /** 按 wumi 公钥取用户，不存在则自动开户 */
    public static function resolveUser(string $publicKey): User
    {
        $publicKey = trim($publicKey);

        $user = (new User())->where('wumi_user_key', $publicKey)->first();
        if ($user !== null) {
            $user->wumi_synced_at = time();
            $user->last_login_time = time();
            $user->save();

            return $user;
        }

        return self::createUser($publicKey);
    }

    /** 由 wumi 公钥自动开户 */
    public static function createUser(string $publicKey): User
    {
        $regConfig = Config::getClass('reg');
        $short = substr(hash('sha256', $publicKey), 0, 12);

        $user = new User();
        $user->user_name = 'wumi_' . $short;
        $user->email = 'wumi_' . $short . '@wumi.local';
        $user->remark = 'wumi 自动开户';
        $user->pass = Hash::passwordHash(Tools::genRandomChar(32));
        $user->passwd = Tools::genRandomChar(16);
        $user->uuid = Uuid::uuid4();
        $user->api_token = Tools::genRandomChar(32);
        $user->port = Tools::getSsPort();
        $user->u = 0;
        $user->d = 0;
        $user->method = $regConfig['reg_method'] ?? 'chacha20-ietf-poly1305';
        $user->transfer_enable = Tools::gbToB($regConfig['reg_traffic'] ?? 0);
        $user->auto_reset_day = Config::obtain('free_user_reset_day');
        $user->auto_reset_bandwidth = Config::obtain('free_user_reset_bandwidth');
        $user->daily_mail_enable = $regConfig['reg_daily_report'] ?? 0;
        $user->money = 0;
        $user->ref_by = 0;
        $user->class = $regConfig['reg_class'] ?? 0;
        $user->class_expire = date('Y-m-d H:i:s', time() + (int) ($regConfig['reg_class_time'] ?? 0) * 86400);
        $user->node_iplimit = $regConfig['reg_ip_limit'] ?? 0;
        $user->node_speedlimit = $regConfig['reg_speed_limit'] ?? 0;
        $user->reg_date = date('Y-m-d H:i:s');
        $user->reg_ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user->theme = $_ENV['theme'] ?? 'tabler';
        $user->locale = $_ENV['locale'] ?? 'zh-cn';
        $user->node_group = 0;
        $user->last_login_time = time();
        $user->wumi_user_key = $publicKey;
        $user->wumi_synced_at = time();

        $user->save();

        return $user;
    }

    /** 内部 API 机器密钥 */
    public static function apiKey(): string
    {
        return (string) Config::obtain('wumi_api_key');
    }

    /** wumi 后端地址（末尾无斜杠） */
    public static function apiUrl(): string
    {
        return rtrim((string) Config::obtain('wumi_api_url'), '/');
    }

    /** wumi JWT 密钥 */
    public static function jwtSecret(): string
    {
        return (string) Config::obtain('wumi_jwt_secret');
    }

    /**
     * 生成用于反向调用 wumi 的服务账号 token（sub = wumi_service_key）。
     */
    public static function serviceToken(int $ttl = 300): string
    {
        $secret = self::jwtSecret();
        if ($secret === '') {
            return '';
        }

        $serviceKey = (string) Config::obtain('wumi_service_key');
        if ($serviceKey === '') {
            $serviceKey = 'sspanel-service';
        }

        return Jwt::encode([
            'sub' => $serviceKey,
            'typ' => 'access',
            'exp' => time() + $ttl,
            'iat' => time(),
        ], $secret);
    }
}