#!/usr/bin/env php
<?php

declare(strict_types=1);

// SSPanel 开发栈测试数据（P7 端到端实测专用，勿用于生产）
//
//   docker compose -f docker/dev/docker-compose.dev.yml exec -T php php docker/dev/seed_dev.php

use App\Models\Config;
use App\Models\Node;
use App\Models\Product;
use App\Services\Boot;
use App\Services\Wumi\Identity;

require __DIR__ . '/../../app/predefine.php';
require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/config/.config.php';

Boot::setTime();
Boot::bootDb();

// 1) wumi 集成配置（机器密钥 / JWT 密钥 / 后端地址）
Config::set('wumi_api_key', 'dev-wumi-api-key');
Config::set('wumi_jwt_secret', 'dev-wumi-jwt-secret-0123456789abcdef');
Config::set('wumi_api_url', 'http://host.docker.internal:8080');
echo 'wumi 配置已写入' . PHP_EOL;

// 2) 绑定用户（按 wumi 公钥自动开户）
$user = Identity::resolveUser('dev-wumi-user-key');
echo 'wumi 用户已就绪 id=' . $user->id . ' uuid=' . $user->uuid . PHP_EOL;

// 3) 在售套餐
$product = new Product();
$product->type = 'tabp';
$product->name = '测试套餐 100G/30天';
$product->price = 9.9;
$product->content = json_encode(['bandwidth' => 100, 'time' => 30]);
$product->limit = json_encode([
    'class_required' => '',
    'node_group_required' => '',
    'new_user_required' => 0,
]);
$product->status = 1;
$product->stock = 100;
$product->sale_count = 0;
$product->create_time = time();
$product->update_time = time();
$product->save();
echo '套餐已就绪 id=' . $product->id . PHP_EOL;

// 4) VLESS（deploy.sh 风格）节点
$node = new Node();
$node->name = '香港节点(dev)';
$node->server = 'signal.example.com';
$node->type = 1;
$node->sort = 20;
$node->traffic_rate = 1;
$node->node_class = 0;
$node->node_group = 0;
$node->node_bandwidth = 0;
$node->node_bandwidth_limit = 0;
$node->node_speedlimit = 0;
$node->custom_config = json_encode([
    'uuid' => '11111111-2222-3333-4444-555555555555',
    'ws_path' => '/devws123/',
    'domain' => 'signal.example.com',
    'signaling_ws_path' => '/signaling/',
    'coturn_port' => 3478,
    'janus_ws_port' => 8188,
]);
$node->password = 'dev-node-password';
$node->save();
echo 'VLESS 节点已就绪 id=' . $node->id . PHP_EOL;
