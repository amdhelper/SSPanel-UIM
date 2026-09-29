<?php

declare(strict_types=1);

namespace App\Services\Subscribe;

use App\Models\Config;
use App\Services\Subscribe;
use App\Services\Xray;
use const PHP_EOL;

/**
 * VLESS 订阅串（WebSocket + TLS）。
 *
 * 对接 mono_zen scripts/deploy.sh 部署的 Xray 节点（接入类型 sort = 20）：
 * 客户端以 vless://<uuid>@<domain>:<port>?type=ws&security=tls&path=<ws_path> 连接，
 * 落到 Xray 443 inbound 的 <ws_path> 回落，转发到内部 8081 的 VLESS/WS inbound。
 *
 * 与 V2Ray 订阅一样，受配置项 enable_v2_sub 控制。
 */
final class VLESS extends Base
{
    public function getContent($user): string
    {
        $links = '';

        if (! Config::obtain('enable_v2_sub')) {
            return $links;
        }

        foreach (Subscribe::getUserNodes($user) as $node_raw) {
            if ((int) $node_raw->sort !== Xray::SORT_VLESS) {
                continue;
            }

            $links .= Xray::vlessUriForNode($node_raw, (string) $user->uuid) . PHP_EOL;
        }

        return $links;
    }
}