<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Services\Xray;
use Tests\TestCase;

/**
 * Xray 节点配置生成器回归测试。
 *
 * 断言重点：面板生成的 Xray config.json 必须与 mono_zen
 * `scripts/deploy.sh signaling_configure_xray()` 的输出逐字段一致，
 * 否则「面板管理 deploy.sh 节点」这条链路会静默产出不可用配置。
 */
final class XrayTest extends TestCase
{
    private function sampleConfig(): array
    {
        return Xray::parseConfig(json_encode([
            'uuid' => '11111111-2222-3333-4444-555555555555',
            'ws_path' => '/ab12cd34/',
            'signaling_ws_path' => '/signaling/',
            'port' => 443,
            'coturn_port' => 3478,
            'janus_ws_port' => 8188,
            'janus_ws_port_internal' => 8189,
            'nginx_fallback_port' => 8080,
            'inner_ws_port' => 8081,
        ]));
    }

    public function testServerConfigMatchesDeployScript(): void
    {
        $out = Xray::generateServerConfig($this->sampleConfig());

        $inbound = $out['inbounds'][0];
        self::assertSame('vless', $inbound['protocol']);
        self::assertSame(443, $inbound['port']);
        self::assertSame('xtls-rprx-vision', $inbound['settings']['clients'][0]['flow']);
        self::assertSame('none', $inbound['settings']['decryption']);
        self::assertSame('tcp', $inbound['streamSettings']['network']);
        self::assertSame('tls', $inbound['streamSettings']['security']);
        self::assertSame(['http/1.1', 'h2'], $inbound['streamSettings']['tlsSettings']['alpn']);
        self::assertSame(
            [
                ['dest' => 8080, 'xver' => 1],
                ['path' => '/ab12cd34/', 'dest' => 8081, 'xver' => 1],
                ['path' => '/signaling/', 'dest' => '127.0.0.1:8189', 'xver' => 0],
                ['path' => '/turn/', 'dest' => '127.0.0.1:3478', 'xver' => 0],
            ],
            $inbound['settings']['fallbacks']
        );

        $ws = $out['inbounds'][1];
        self::assertSame(8081, $ws['port']);
        self::assertSame('127.0.0.1', $ws['listen']);
        self::assertSame('ws', $ws['streamSettings']['network']);
        self::assertSame('/ab12cd34/', $ws['streamSettings']['wsSettings']['path']);

        self::assertSame('direct', $out['outbounds'][0]['tag']);
        self::assertSame('blocked', $out['outbounds'][1]['tag']);
    }

    public function testVlessUriIsWellFormed(): void
    {
        $uri = Xray::buildVlessUri(
            '11111111-2222-3333-4444-555555555555',
            'signal.example.com',
            $this->sampleConfig(),
            '香港节点'
        );

        self::assertStringStartsWith(
            'vless://11111111-2222-3333-4444-555555555555@signal.example.com:443?',
            $uri
        );
        self::assertStringContainsString('encryption=none', $uri);
        self::assertStringContainsString('security=tls', $uri);
        self::assertStringContainsString('type=ws', $uri);
        self::assertStringContainsString('path=%2Fab12cd34%2F', $uri);
        self::assertStringEndsWith('#' . rawurlencode('香港节点'), $uri);
    }

    public function testDeployCommandUsesAutoInstall(): void
    {
        $cmd = Xray::buildDeployCommand(
            $this->sampleConfig(),
            'signal.example.com',
            'https://1230611.xyz/',
            'SECRET',
            '香港节点'
        );

        self::assertStringContainsString(
            './scripts/deploy.sh signaling auto-install signal.example.com https://1230611.xyz SECRET 香港节点',
            $cmd
        );
    }

    public function testRegisterPayloadShapeMatchesBackend(): void
    {
        $p = Xray::buildRegisterPayload($this->sampleConfig(), 'signal.example.com', '香港节点', '1.2.3.4', 'SECRET');

        self::assertSame(
            ['name', 'domain', 'port', 'node_type', 'turn_username', 'turn_password', 'turn_port',
                'turn_secret', 'turn_auth_mode', 'janus_ws_port', 'public_ip', 'secret_key'],
            array_keys($p)
        );
        self::assertSame(443, $p['port']);
        self::assertSame(3478, $p['turn_port']);
        self::assertSame(8188, $p['janus_ws_port']);
    }

    public function testPathNormalization(): void
    {
        $bare = Xray::generateServerConfig(Xray::parseConfig(json_encode(['ws_path' => 'xyz'])));
        self::assertSame('/xyz/', $bare['inbounds'][0]['settings']['fallbacks'][1]['path']);

        $noTrailing = Xray::generateServerConfig(Xray::parseConfig(json_encode(['ws_path' => '/no-trailing'])));
        self::assertSame('/no-trailing/', $noTrailing['inbounds'][0]['settings']['fallbacks'][1]['path']);
    }

    public function testNodeSortRecognizesVless(): void
    {
        $node = new \App\Models\Node();
        $node->sort = Xray::SORT_VLESS;

        self::assertSame('VLESS (Xray)', $node->sort());
    }
}