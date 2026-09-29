<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Node;
use function array_key_exists;
use function array_merge;
use function implode;
use function is_array;
use function is_int;
use function json_decode;
use function json_encode;
use function ltrim;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function str_contains;
use function strtolower;
use function trim;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Xray 节点配置生成器（VLESS + WebSocket/TLS + 信令/Janus/Coturn 回落）。
 *
 * 目标：让 SSPanel 「完美支持」由 mono_zen `scripts/deploy.sh signaling` 部署出来的
 * Xray 节点。deploy.sh 的直连模式节点形态为：
 *
 *   - Xray 监听 443，协议 vless，流控 xtls-rprx-vision；
 *   - 同一 inbound 上挂 4 条 fallback：
 *       · 默认 → Nginx(8080)          —— 伪装站
 *       · <ws_path> → 8081 (VLESS/WS) —— 梯子客户端入口
 *       · /signaling/ → 127.0.0.1:8189 —— wumi 信令(Janus)
 *       · /turn/ → 127.0.0.1:3478      —— TURN over fallback
 *   - 证书 /ssl/xray.crt|key。
 *
 * 本类的 generateServerConfig() 逐字段复刻 deploy.sh 的 signaling_configure_xray()，
 * 因此面板产出的节点配置与现网完全一致；用户端订阅串（buildVlessUri）与
 * buildDeployCommand() 亦按同一套参数生成。
 *
 * 参数来源：Node->custom_config（JSON），缺省值见 DEFAULTS。
 */
final class Xray
{
    /** 接入类型：deploy.sh 风格 Xray 节点（VLESS + WS/TLS + 信令回落） */
    public const SORT_VLESS = 20;

    /** 缺省参数（与 deploy.sh 对齐，可由 node.custom_config 覆盖） */
    public const DEFAULTS = [
        'port' => 443,
        'ws_path' => '/ws/',
        'signaling_ws_path' => '/signaling/',
        'coturn_port' => 3478,
        'janus_ws_port' => 8188,          // 对外端口（Nginx 反代）
        'janus_ws_port_internal' => 8189, // Xray 回落目标（仅 127.0.0.1）
        'nginx_fallback_port' => 8080,
        'inner_ws_port' => 8081,          // 内部 WS inbound（仅 127.0.0.1）
        'turn_path' => '/turn/',
        'flow' => 'xtls-rprx-vision',
        'alpn' => ['http/1.1', 'h2'],
        'cert_file' => '/ssl/xray.crt',
        'key_file' => '/ssl/xray.key',
        'access_log' => '/var/log/xray/access.log',
        'error_log' => '/var/log/xray/error.log',
        'loglevel' => 'warning',
        'direct' => true,
    ];

    /**
     * 解析并归一节点自定义配置。
     *
     * @return array<string, mixed>
     */
    public static function parseConfig(?string $custom_config): array
    {
        $decoded = json_decode((string) $custom_config, true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        return array_merge(self::DEFAULTS, $decoded);
    }

    /**
     * 生成与 deploy.sh signaling_configure_xray() 完全一致的 Xray config.json。
     *
     * @param  array<string, mixed> $config 归一后的节点配置
     * @return array<string, mixed>
     */
    public static function generateServerConfig(array $config): array
    {
        $c = array_merge(self::DEFAULTS, $config);

        $port = self::asInt($c['port'], 443);
        $ws_path = self::normalizePath((string) $c['ws_path']);
        $signaling_ws_path = self::normalizePath((string) $c['signaling_ws_path']);
        $turn_path = self::normalizePath((string) $c['turn_path']);
        $inner_ws_port = self::asInt($c['inner_ws_port'], 8081);
        $nginx_fallback_port = self::asInt($c['nginx_fallback_port'], 8080);
        $janus_internal = self::asInt($c['janus_ws_port_internal'], 8189);
        $coturn_port = self::asInt($c['coturn_port'], 3478);

        $uuid = (string) ($c['uuid'] ?? '');
        $flow = (string) ($c['flow'] ?? 'xtls-rprx-vision');
        $alpn = is_array($c['alpn']) ? $c['alpn'] : self::DEFAULTS['alpn'];

        return [
            'log' => [
                'access' => (string) $c['access_log'],
                'error' => (string) $c['error_log'],
                'loglevel' => (string) $c['loglevel'],
            ],
            'inbounds' => [
                [
                    'port' => $port,
                    'listen' => '0.0.0.0',
                    'protocol' => 'vless',
                    'settings' => [
                        'clients' => [
                            [
                                'id' => $uuid,
                                'flow' => $flow,
                            ],
                        ],
                        'decryption' => 'none',
                        'fallbacks' => [
                            [
                                'dest' => $nginx_fallback_port,
                                'xver' => 1,
                            ],
                            [
                                'path' => $ws_path,
                                'dest' => $inner_ws_port,
                                'xver' => 1,
                            ],
                            [
                                'path' => $signaling_ws_path,
                                'dest' => '127.0.0.1:' . $janus_internal,
                                'xver' => 0,
                            ],
                            [
                                'path' => $turn_path,
                                'dest' => '127.0.0.1:' . $coturn_port,
                                'xver' => 0,
                            ],
                        ],
                    ],
                    'streamSettings' => [
                        'network' => 'tcp',
                        'security' => 'tls',
                        'tlsSettings' => [
                            'alpn' => $alpn,
                            'certificates' => [
                                [
                                    'certificateFile' => (string) $c['cert_file'],
                                    'keyFile' => (string) $c['key_file'],
                                ],
                            ],
                        ],
                    ],
                    'sniffing' => [
                        'enabled' => true,
                        'destOverride' => ['http', 'tls'],
                    ],
                ],
                [
                    'port' => $inner_ws_port,
                    'listen' => '127.0.0.1',
                    'protocol' => 'vless',
                    'settings' => [
                        'clients' => [
                            [
                                'id' => $uuid,
                            ],
                        ],
                        'decryption' => 'none',
                    ],
                    'streamSettings' => [
                        'network' => 'ws',
                        'wsSettings' => [
                            'path' => $ws_path,
                        ],
                    ],
                ],
            ],
            'outbounds' => [
                [
                    'protocol' => 'freedom',
                    'tag' => 'direct',
                ],
                [
                    'protocol' => 'blackhole',
                    'tag' => 'blocked',
                ],
            ],
        ];
    }

    /**
     * 生成人类可读的 config.json（供面板展示/下发）。
     *
     * @param  array<string, mixed> $config
     */
    public static function generateServerConfigJson(array $config): string
    {
        return (string) json_encode(
            self::generateServerConfig($config),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    /**
     * 生成"一键部署"命令：deploy.sh 的 signaling auto-install（非交互式全流程）。
     *
     * @param  array<string, mixed> $config
     */
    public static function buildDeployCommand(
        array $config,
        string $domain,
        string $apiUrl,
        string $secretKey,
        string $nodeName,
        bool $cdn = false
    ): string {
        $c = array_merge(self::DEFAULTS, $config);

        $flags = [];
        if (! ($c['direct'] ?? true)) {
            $flags[] = '--skip-web';
        }
        if ($cdn) {
            $flags[] = '--cdn';
        }
        $flagStr = $flags === [] ? '' : ' ' . implode(' ', $flags);

        return sprintf(
            "# 在节点服务器上以 root 执行（mono_zen 仓库根目录）：\n"
            . "./scripts/deploy.sh signaling auto-install %s %s %s %s%s",
            $domain,
            rtrim($apiUrl, '/'),
            $secretKey,
            $nodeName,
            $flagStr
        );
    }

    /**
     * 生成节点注册到 wumi 主站的请求体（等价 deploy.sh 的 signaling_auto_register）。
     *
     * @param  array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function buildRegisterPayload(
        array $config,
        string $domain,
        string $nodeName,
        string $publicIp,
        string $secretKey,
        string $nodeType = 'full',
        string $turnUsername = '',
        string $turnPassword = '',
        string $turnSecret = '',
        string $turnAuthMode = 'short_cred'
    ): array {
        $c = array_merge(self::DEFAULTS, $config);

        return [
            'name' => $nodeName,
            'domain' => $domain,
            'port' => self::asInt($c['port'], 443),
            'node_type' => $nodeType,
            'turn_username' => $turnUsername,
            'turn_password' => $turnPassword,
            'turn_port' => self::asInt($c['coturn_port'], 3478),
            'turn_secret' => $turnSecret,
            'turn_auth_mode' => $turnAuthMode,
            'janus_ws_port' => self::asInt($c['janus_ws_port'], 8188),
            'public_ip' => $publicIp,
            'secret_key' => $secretKey,
        ];
    }

    /**
     * 构建面向客户端的 VLESS 订阅串（WebSocket + TLS，走 deploy.sh 的 ws_path 回落）。
     *
     * vless://<uuid>@<host>:<port>?encryption=none&security=tls&sni=<sni>&type=ws
     *         &host=<host>&path=<ws_path>&fp=chrome#<name>
     *
     * @param  array<string, mixed> $config
     */
    public static function buildVlessUri(
        string $uuid,
        string $host,
        array $config,
        string $name,
        string $sni = '',
        string $fingerprint = 'chrome'
    ): string {
        $c = array_merge(self::DEFAULTS, $config);

        $port = self::asInt($c['port'], 443);
        $ws_path = self::normalizePath((string) $c['ws_path']);
        $sni = $sni !== '' ? $sni : $host;

        $query = [
            'encryption' => 'none',
            'security' => 'tls',
            'sni' => $sni,
            'type' => 'ws',
            'host' => $host,
            'path' => $ws_path,
            'fp' => $fingerprint,
        ];

        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = $k . '=' . rawurlencode((string) $v);
        }

        return sprintf(
            'vless://%s@%s:%d?%s#%s',
            $uuid,
            $host,
            $port,
            implode('&', $pairs),
            rawurlencode($name)
        );
    }

    /**
     * 从节点模型便捷生成订阅串。
     */
    public static function vlessUriForNode(Node $node, string $uuid): string
    {
        return self::buildVlessUri(
            $uuid,
            (string) $node->server,
            self::parseConfig($node->custom_config),
            (string) $node->name
        );
    }

    private static function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }
        if (! str_contains($path, '/')) {
            $path = '/' . $path;
        }
        $path = ltrim($path, "\t\n\r ");
        if (! str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        if (! str_ends_with($path, '/')) {
            $path .= '/';
        }

        return $path;
    }

    private static function asInt(mixed $value, int $default): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && strtolower($value) !== 'null') {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return $default;
    }
}