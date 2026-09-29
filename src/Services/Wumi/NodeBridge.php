<?php

declare(strict_types=1);

namespace App\Services\Wumi;

use App\Models\Node;
use App\Utils\Tools;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use function array_key_exists;
use function json_decode;
use function json_encode;
use function strtolower;
use function time;
use function trim;

/**
 * wumi 节点桥（运行态 ↔ 配置态）。
 *
 * 分工（爸爸 2026-09-30 决策）：
 * - SSPanel 是「配置态」权威：等级 / 倍率 / 限速 / 流量上限 / 设备数限制。
 * - wumi `signaling_nodes` 是「运行态」权威：在线状态 / 心跳 / TURN 凭据。
 *
 * 本类只做「拉取运行态并回灌 SSPanel」一个方向：以服务账号 token 调 wumi
 * `GET /nodes`，按 domain/public_ip 匹配 SSPanel node 行，回写
 * node_heartbeat / online_user / ipv4 / custom_config.wumi_node_id。
 */
final class NodeBridge
{
    /**
     * 拉取 wumi 节点（含运行态）。失败返回空数组。
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetchWumiNodes(): array
    {
        $apiUrl = Identity::apiUrl();
        $token = Identity::serviceToken();

        if ($apiUrl === '' || $token === '') {
            return [];
        }

        try {
            $client = new Client(['timeout' => 10, 'http_errors' => false]);
            $response = $client->get($apiUrl . '/nodes', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]);

            if ($response->getStatusCode() !== 200) {
                return [];
            }

            $body = json_decode((string) $response->getBody(), true);
            if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
                return [];
            }

            return $body['data'];
        } catch (GuzzleException) {
            return [];
        }
    }

    /**
     * 同步运行态到 SSPanel node 表。
     *
     * @return array{matched:int, updated:int, total:int}
     */
    public static function sync(): array
    {
        $wumiNodes = self::fetchWumiNodes();

        $result = [
            'matched' => 0,
            'updated' => 0,
            'total' => count($wumiNodes),
        ];

        if ($wumiNodes === []) {
            return $result;
        }

        // 建立 wumi 节点索引：domain / public_ip 归一为小写键
        $index = [];
        foreach ($wumiNodes as $wn) {
            foreach (['domain', 'public_ip'] as $field) {
                $key = strtolower(trim((string) ($wn[$field] ?? '')));
                if ($key !== '') {
                    $index[$key] = $wn;
                }
            }
        }

        foreach ((new Node())->get() as $node) {
            $serverKey = strtolower(trim((string) $node->server));
            if ($serverKey === '' || ! array_key_exists($serverKey, $index)) {
                continue;
            }

            $wn = $index[$serverKey];
            $result['matched']++;

            $isOnline = (bool) ($wn['is_online'] ?? false);
            $node->node_heartbeat = $isOnline ? time() : 0;
            $node->online_user = (int) ($wn['online_user'] ?? 0);

            $publicIp = trim((string) ($wn['public_ip'] ?? ''));
            if ($publicIp !== '') {
                $node->ipv4 = $publicIp;
            }

            $custom = json_decode((string) $node->custom_config, true);
            if (! is_array($custom)) {
                $custom = [];
            }
            $custom['wumi_node_id'] = $wn['id'] ?? ($custom['wumi_node_id'] ?? null);
            $custom['wumi_is_online'] = $isOnline;
            $custom['wumi_synced_at'] = time();
            $node->custom_config = json_encode($custom);

            // 记录已用流量（wumi 侧流量若存在则沿用；否则保持 SSPanel 值）
            if (isset($wn['node_bandwidth'])) {
                $node->node_bandwidth = (int) $wn['node_bandwidth'];
            }

            $node->save();
            $result['updated']++;
        }

        return $result;
    }

    /** 供面板展示：配置态节点列表（含运行态标记） */
    public static function listConfigNodes(): array
    {
        $out = [];

        foreach ((new Node())->orderBy('node_class')->orderBy('name')->get() as $node) {
            $out[] = [
                'id' => (int) $node->id,
                'name' => $node->name,
                'server' => $node->server,
                'sort' => (int) $node->sort,
                'type_label' => $node->sort(),
                'node_class' => (int) $node->node_class,
                'node_group' => (int) $node->node_group,
                'traffic_rate' => (float) $node->traffic_rate,
                'node_speedlimit' => (int) $node->node_speedlimit,
                'node_bandwidth' => (int) $node->node_bandwidth,
                'node_bandwidth_limit' => (int) $node->node_bandwidth_limit,
                'node_bandwidth_gb' => round(Tools::bToGB($node->node_bandwidth), 3),
                'node_bandwidth_limit_gb' => Tools::bToGB($node->node_bandwidth_limit),
                'online_user' => (int) $node->online_user,
                'node_heartbeat' => (int) $node->node_heartbeat,
                'is_online' => $node->getNodeOnlineStatus() === 1,
                'status' => $node->getNodeOnlineStatus(),
            ];
        }

        return $out;
    }
}