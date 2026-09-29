<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Services\Wumi\NodeBridge;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;

/**
 * wumi 节点桥控制器（供 wumi「信令节点管理」Tab / 后端代理调用）。
 *
 * - GET  /wumi/api/v1/nodes      配置态节点列表（等级/倍率/限速/流量/在线）
 * - POST /wumi/api/v1/nodes/sync 拉取 wumi 运行态并回灌 SSPanel node 表
 */
final class NodeController extends BaseController
{
    /** GET /wumi/api/v1/nodes */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return $response->withJson([
            'ret' => 1,
            'data' => NodeBridge::listConfigNodes(),
        ]);
    }

    /** POST /wumi/api/v1/nodes/sync */
    public function sync(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $result = NodeBridge::sync();

        return $response->withJson([
            'ret' => 1,
            'msg' => '节点运行态同步完成',
            'data' => $result,
        ]);
    }
}