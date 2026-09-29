<?php

declare(strict_types=1);

namespace App\Command;

use App\Services\Wumi\NodeBridge;
use const PHP_EOL;

/**
 * php xcat WumiSyncNodes — 拉取 wumi 信令节点运行态并回灌 SSPanel node 表。
 */
final class WumiSyncNodes extends Command
{
    public string $description = <<< END
├─=: php xcat WumiSyncNodes - 同步 wumi 信令节点运行态（在线/心跳/流量）
END;

    public function boot(): void
    {
        $result = NodeBridge::sync();

        echo 'wumi 节点同步完成: total=' . $result['total']
            . ' matched=' . $result['matched']
            . ' updated=' . $result['updated'] . PHP_EOL;

        if ($result['total'] === 0) {
            echo '（未取到 wumi 节点：请检查 wumi_api_url / wumi_jwt_secret 配置与 wumi 后端可达性）' . PHP_EOL;
        }
    }
}