<?php

declare(strict_types=1);

namespace App\Services\Wumi;

use App\Services\Xray;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use function base64_decode;
use function explode;
use function in_array;
use function is_array;
use function json_decode;
use function parse_str;
use function parse_url;
use function preg_split;
use function rawurldecode;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function strtr;
use function substr;
use function trim;
use const PHP_URL_SCHEME;

/**
 * 节点「链接导入」解析器（wumi 站点管理 → 信令节点管理 → 用链接添加节点）。
 *
 * 支持的输入：
 *   1. 单条节点分享链接：vless:// / vmess:// / trojan:// / ss://
 *   2. 订阅链接（http/https，返回 base64 或纯文本节点列表）
 *   3. 直接粘贴的 base64 订阅内容
 *
 * 解析结果为一组「节点字段数组」，交给 NodeAdminController::apply() 落库。
 * VLESS 链接会被还原成 deploy.sh 风格的 custom_config（port / ws_path / flow），
 * 因此以分享链接导入的节点与 deploy.sh signaling 节点配置一致。
 */
final class NodeImport
{
    /** 可识别的分享链接协议 */
    public const SCHEMES = ['vless', 'vmess', 'trojan', 'ss'];

    /** 协议 → SSPanel Node.sort */
    private const SORT_MAP = [
        'vless' => Xray::SORT_VLESS, // 20
        'vmess' => 11,
        'trojan' => 14,
        'ss' => 0,
    ];

    public static function isShareLink(string $input): bool
    {
        foreach (self::SCHEMES as $scheme) {
            if (str_starts_with(strtolower(trim($input)), $scheme . '://')) {
                return true;
            }
        }

        return false;
    }

    public static function isSubscriptionUrl(string $input): bool
    {
        $input = strtolower(trim($input));

        return str_starts_with($input, 'http://') || str_starts_with($input, 'https://');
    }

    /**
     * 解析任意输入 → 节点字段数组列表。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function parseInput(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return [];
        }

        // 单行且是分享链接 → 直接解析。
        // 🔴 必须先判「单行」：多行粘贴（一行一条）时整段文本也以 vless:// 开头，
        // 若在此直接 parseLink 整段，会把 12 条链接当成 1 条解析 → 解析出 0 个节点。
        if (! preg_match('/[\r\n]/', $input) && self::isShareLink($input)) {
            $node = self::parseLink($input);

            return $node === null ? [] : [$node];
        }

        if (self::isSubscriptionUrl($input)) {
            return self::fetchSubscription($input);
        }

        // 其余按 base64 订阅内容处理（多行链接）
        $links = self::decodeSubscription($input);
        if ($links === []) {
            // 最后兜底：按纯文本多行分享链接
            $links = explode("\n", $input);
        }

        $out = [];
        foreach ($links as $line) {
            $line = trim($line);
            if ($line !== '' && self::isShareLink($line)) {
                $node = self::parseLink($line);
                if ($node !== null) {
                    $out[] = $node;
                }
            }
        }

        return $out;
    }

    /**
     * 解析单条分享链接。
     *
     * @return array<string, mixed>|null
     */
    public static function parseLink(string $link): ?array
    {
        $link = trim($link);
        $scheme = strtolower((string) parse_url($link, PHP_URL_SCHEME));

        if (! in_array($scheme, self::SCHEMES, true)) {
            return null;
        }

        return match ($scheme) {
            'vless' => self::parseVless($link),
            'vmess' => self::parseVmess($link),
            'trojan' => self::parseTrojan($link),
            'ss' => self::parseSs($link),
            default => null,
        };
    }

    /**
     * 订阅内容（base64 或纯文本）→ 链接数组。
     *
     * @return array<int, string>
     */
    public static function decodeSubscription(string $body): array
    {
        $body = trim($body);

        if ($body === '') {
            return [];
        }

        // 尝试 urlsafe base64（订阅常见形态）
        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        if ($decoded !== false && self::containsLink($decoded)) {
            $body = $decoded;
        }

        $lines = [];
        foreach (preg_split('/[\r\n]+/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * 拉取订阅链接并按行解析。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetchSubscription(string $url): array
    {
        try {
            $client = new Client([
                'timeout' => 15,
                'http_errors' => false,
                'headers' => ['User-Agent' => 'SSPanel-Wumi/1.0'],
            ]);
            $response = $client->get($url);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return [];
            }

            $body = (string) $response->getBody();
        } catch (GuzzleException) {
            return [];
        }

        $out = [];
        foreach (self::decodeSubscription($body) as $line) {
            if (! self::isShareLink($line)) {
                continue;
            }
            $node = self::parseLink($line);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // 各协议解析
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    private static function parseVless(string $link): ?array
    {
        $parts = parse_url($link);
        if (! is_array($parts)) {
            return null;
        }

        $server = trim((string) ($parts['host'] ?? ''));
        if ($server === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? 443);
        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        $wsPath = (string) ($query['path'] ?? Xray::DEFAULTS['ws_path']);
        $flow = (string) ($query['flow'] ?? '');
        $alpn = isset($query['alpn']) ? explode(',', (string) $query['alpn']) : Xray::DEFAULTS['alpn'];

        $custom = [
            'port' => $port,
            'ws_path' => $wsPath,
            'alpn' => $alpn,
            'source_uri' => $link,
        ];
        if ($flow !== '') {
            $custom['flow'] = $flow;
        }
        if (isset($query['sni']) && (string) $query['sni'] !== '') {
            $custom['sni'] = (string) $query['sni'];
        }

        return [
            'name' => self::linkName($parts, $server),
            'server' => $server,
            'sort' => Xray::SORT_VLESS,
            'custom_config' => $custom,
        ];
    }

    /** @return array<string, mixed>|null */
    private static function parseVmess(string $link): ?array
    {
        $raw = substr($link, strlen('vmess://'));
        $decoded = base64_decode(strtr(trim($raw), '-_', '+/'), true);
        $conf = $decoded === false ? null : json_decode($decoded, true);

        if (! is_array($conf)) {
            return null;
        }

        $server = trim((string) ($conf['add'] ?? ''));
        if ($server === '') {
            return null;
        }

        return [
            'name' => trim((string) ($conf['ps'] ?? $server)),
            'server' => $server,
            'sort' => self::SORT_MAP['vmess'],
            'custom_config' => [
                'port' => (int) ($conf['port'] ?? 443),
                'ws_path' => (string) ($conf['path'] ?? '/'),
                'network' => (string) ($conf['net'] ?? 'tcp'),
                'source_uri' => $link,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function parseTrojan(string $link): ?array
    {
        $parts = parse_url($link);
        if (! is_array($parts)) {
            return null;
        }

        $server = trim((string) ($parts['host'] ?? ''));
        if ($server === '') {
            return null;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
        }

        return [
            'name' => self::linkName($parts, $server),
            'server' => $server,
            'sort' => self::SORT_MAP['trojan'],
            'custom_config' => [
                'port' => (int) ($parts['port'] ?? 443),
                'ws_path' => (string) ($query['path'] ?? '/'),
                'source_uri' => $link,
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    private static function parseSs(string $link): ?array
    {
        $body = substr($link, strlen('ss://'));
        $fragment = '';
        if (str_contains($body, '#')) {
            [$body, $fragment] = explode('#', $body, 2);
        }
        $fragment = rawurldecode($fragment);

        $query = '';
        if (str_contains($body, '?')) {
            [$body, $query] = explode('?', $body, 2);
        }

        $server = '';
        $port = 443;

        if (str_contains($body, '@')) {
            // ss://base64(method:pass)@host:port
            $hostPart = substr($body, (int) strpos($body, '@') + 1);
            $hp = explode(':', $hostPart);
            $server = trim((string) ($hp[0] ?? ''));
            $port = (int) ($hp[1] ?? 443);
        } else {
            // ss://base64(method:pass@host:port)
            $decoded = base64_decode(strtr($body, '-_', '+/'), true);
            if ($decoded !== false && str_contains($decoded, '@')) {
                $hostPart = substr($decoded, (int) strpos($decoded, '@') + 1);
                $hp = explode(':', $hostPart);
                $server = trim((string) ($hp[0] ?? ''));
                $port = (int) ($hp[1] ?? 443);
            }
        }

        if ($server === '') {
            return null;
        }

        return [
            'name' => $fragment !== '' ? $fragment : $server,
            'server' => $server,
            'sort' => self::SORT_MAP['ss'],
            'custom_config' => [
                'port' => $port,
                'ws_path' => '/',
                'source_uri' => $link,
            ],
        ];
    }

    private static function containsLink(string $text): bool
    {
        foreach (self::SCHEMES as $scheme) {
            if (str_contains($text, $scheme . '://')) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $parts */
    private static function linkName(array $parts, string $fallback): string
    {
        $frag = isset($parts['fragment']) ? rawurldecode((string) $parts['fragment']) : '';

        return trim($frag) !== '' ? trim($frag) : $fallback;
    }
}