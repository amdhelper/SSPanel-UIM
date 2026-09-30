<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use App\Services\Wumi\NodeImport;
use Tests\TestCase;
use function base64_encode;
use function implode;
use function json_encode;

/**
 * 节点「链接导入」解析器回归测试（无 DB / 无网络）。
 *
 * 关键不变量：vless 链接必须还原成 deploy.sh 风格 custom_config（port / ws_path），
 * 且 sort=20（VLESS/Xray）；订阅 base64 内容需逐行解析。
 */
final class WumiNodeImportTest extends TestCase
{
    private const VLESS = 'vless://abc-uuid@node.example.com:443?encryption=none&security=tls&type=ws&host=node.example.com&path=%2Fws123%2F&sni=node.example.com&fp=chrome#Tokyo-1';

    public function testParseVlessLinkKeepsDeployStyleConfig(): void
    {
        $node = NodeImport::parseLink(self::VLESS);

        self::assertIsArray($node);
        self::assertSame('node.example.com', $node['server']);
        self::assertSame(20, $node['sort']);
        self::assertSame('Tokyo-1', $node['name']);
        self::assertSame(443, $node['custom_config']['port']);
        self::assertSame('/ws123/', $node['custom_config']['ws_path']);
    }

    public function testParseInputHandlesSingleShareLink(): void
    {
        $parsed = NodeImport::parseInput(self::VLESS);

        self::assertCount(1, $parsed);
        self::assertSame('node.example.com', $parsed[0]['server']);
    }

    public function testParseTrojanAndVmessAndSs(): void
    {
        $trojan = NodeImport::parseLink('trojan://pass@t.example.com:8443?sni=t.example.com#TJ');
        self::assertIsArray($trojan);
        self::assertSame('t.example.com', $trojan['server']);
        self::assertSame(14, $trojan['sort']);

        $vmessConf = json_encode([
            'v' => '2', 'ps' => 'VM', 'add' => 'v.example.com', 'port' => '8080',
            'id' => 'uuid', 'net' => 'ws', 'path' => '/vm',
        ]);
        $vmess = NodeImport::parseLink('vmess://' . base64_encode((string) $vmessConf));
        self::assertIsArray($vmess);
        self::assertSame('v.example.com', $vmess['server']);
        self::assertSame(11, $vmess['sort']);

        $ss = NodeImport::parseLink('ss://' . base64_encode('aes-256-gcm:pw@ss.example.com:9000') . '#SS');
        self::assertIsArray($ss);
        self::assertSame('ss.example.com', $ss['server']);
        self::assertSame(0, $ss['sort']);
    }

    public function testParseInputDecodesBase64Subscription(): void
    {
        $lines = implode("\n", [
            self::VLESS,
            'trojan://pass@t2.example.com:443#TJ2',
        ]);
        $parsed = NodeImport::parseInput(base64_encode($lines));

        self::assertCount(2, $parsed);
        self::assertSame('node.example.com', $parsed[0]['server']);
        self::assertSame('t2.example.com', $parsed[1]['server']);
    }

    public function testRejectsUnparsableInput(): void
    {
        self::assertSame([], NodeImport::parseInput(''));
        self::assertSame([], NodeImport::parseInput('hello world'));
        self::assertNull(NodeImport::parseLink('http://not-a-node-link'));
    }
}