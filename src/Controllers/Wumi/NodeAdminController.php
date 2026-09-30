<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\Node;
use App\Services\Wumi\NodeImport;
use App\Services\Xray;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function strtolower;
use function trim;

/**
 * wumi「信令节点管理」写操作（增删改 / 启停 / 重置流量 / 用链接导入）。
 *
 * 供 wumi 后端以机器密钥（X-Wumi-Api-Key）调用，代表站点管理员操作 SSPanel
 * node 表 —— 即把 SSPanel 后台「节点管理」的全部能力开放给 wumi 站务管理页。
 *
 *   GET    /wumi/api/v1/nodes/{id}                 单个节点详情（编辑表单回填）
 *   POST   /wumi/api/v1/nodes                      新建节点
 *   POST   /wumi/api/v1/nodes/{id}                 修改节点
 *   DELETE /wumi/api/v1/nodes/{id}                 删除节点
 *   POST   /wumi/api/v1/nodes/{id}/toggle          启用/隐藏
 *   POST   /wumi/api/v1/nodes/{id}/reset-bandwidth 重置已用流量
 *   POST   /wumi/api/v1/nodes/import               用分享链接/订阅链接批量导入
 *
 * ⚠️ 路径**不得包含 `/admin`**（SSPanel 全局 ErrorHandler 会 302 到 /auth/login）。
 * 返回值统一 `{ret:1, data:...}`，与 wumi LadderService 的解包约定一致。
 */
final class NodeAdminController extends BaseController
{
    /** GET /wumi/api/v1/nodes/{id} */
    public function show(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node = (new Node())->find($args['id']);
        if ($node === null) {
            return self::fail($response, '节点不存在');
        }

        return self::ok($response, self::nodeToArray($node));
    }

    /** POST /wumi/api/v1/nodes */
    public function create(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $params = $request->getParams();

        $name = trim((string) ($params['name'] ?? ''));
        $server = trim((string) ($params['server'] ?? ''));
        if ($name === '' || $server === '') {
            return self::fail($response, '节点名称与地址不能为空');
        }

        $node = new Node();
        $node->password = Tools::genRandomChar(32);
        $node->node_bandwidth = 0;
        $node->online_user = 0;
        $node->node_heartbeat = 0;
        self::apply($node, $params);

        if (! $node->save()) {
            return self::fail($response, '添加失败');
        }

        $node->updateNodeIp();
        $node->save();

        return self::ok($response, ['node_id' => (int) $node->id, 'node' => self::nodeToArray($node)]);
    }

    /** POST /wumi/api/v1/nodes/{id} */
    public function update(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node = (new Node())->find($args['id']);
        if ($node === null) {
            return self::fail($response, '节点不存在');
        }

        $params = $request->getParams();
        self::apply($node, $params);

        if (! $node->save()) {
            return self::fail($response, '修改失败');
        }

        // 地址变化 → 重算 IP
        if (isset($params['server'])) {
            $node->updateNodeIp();
            $node->save();
        }

        return self::ok($response, ['node' => self::nodeToArray($node)]);
    }

    /** DELETE /wumi/api/v1/nodes/{id} */
    public function delete(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node = (new Node())->find($args['id']);
        if ($node === null) {
            return self::fail($response, '节点不存在');
        }

        if (! $node->delete()) {
            return self::fail($response, '删除失败');
        }

        return self::ok($response, ['deleted' => true, 'node_id' => (int) $args['id']]);
    }

    /** POST /wumi/api/v1/nodes/{id}/toggle —— 启用/隐藏切换 */
    public function toggle(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node = (new Node())->find($args['id']);
        if ($node === null) {
            return self::fail($response, '节点不存在');
        }

        $node->type = (int) $node->type === 1 ? 0 : 1;
        $node->save();

        return self::ok($response, ['node_id' => (int) $node->id, 'type' => (int) $node->type]);
    }

    /** POST /wumi/api/v1/nodes/{id}/reset-bandwidth */
    public function resetBandwidth(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $node = (new Node())->find($args['id']);
        if ($node === null) {
            return self::fail($response, '节点不存在');
        }

        $node->node_bandwidth = 0;
        $node->save();

        return self::ok($response, ['node_id' => (int) $node->id, 'node_bandwidth' => 0]);
    }

    /** POST /wumi/api/v1/nodes/import —— 用分享链接/订阅链接导入节点 */
    public function import(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $input = trim((string) $request->getParam('input', $request->getParam('link', '')));
        if ($input === '') {
            return self::fail($response, '请输入节点分享链接或订阅链接');
        }

        $parsed = NodeImport::parseInput($input);
        if ($parsed === []) {
            return self::fail($response, '未从链接中解析出可用节点，请检查链接格式');
        }

        // 导入时可整体设定等级/组别/倍率/显示状态（默认沿用链接自带）
        $override = [];
        foreach (['node_class', 'node_group', 'traffic_rate', 'node_speedlimit', 'type'] as $key) {
            if ($request->getParam($key) !== null) {
                $override[$key] = $request->getParam($key);
            }
        }

        $created = [];
        $updated = [];
        $skipped = [];

        foreach ($parsed as $item) {
            if (! is_array($item) || trim((string) ($item['server'] ?? '')) === '') {
                continue;
            }

            $server = trim((string) $item['server']);
            $existing = (new Node())->where('server', $server)->first();

            if ($existing !== null) {
                $existing->custom_config = json_encode($item['custom_config'] ?? []);
                if (trim((string) ($item['name'] ?? '')) !== '') {
                    $existing->name = (string) $item['name'];
                }
                if (isset($item['sort'])) {
                    $existing->sort = (int) $item['sort'];
                }
                foreach ($override as $k => $v) {
                    self::applyScalar($existing, $k, $v);
                }
                $existing->save();
                $updated[] = self::nodeToArray($existing);
                continue;
            }

            $node = new Node();
            $node->name = trim((string) ($item['name'] ?? $server));
            $node->server = $server;
            $node->sort = (int) ($item['sort'] ?? Xray::SORT_VLESS);
            $node->node_class = 0;
            $node->node_group = 0;
            $node->traffic_rate = 1;
            $node->type = 1;
            $node->password = Tools::genRandomChar(32);
            $node->node_bandwidth = 0;
            $node->online_user = 0;
            $node->node_heartbeat = 0;
            $node->custom_config = json_encode($item['custom_config'] ?? []);

            foreach ($override as $k => $v) {
                self::applyScalar($node, $k, $v);
            }

            if (! $node->save()) {
                $skipped[] = $server;
                continue;
            }

            $node->updateNodeIp();
            $node->save();
            $created[] = self::nodeToArray($node);
        }

        return self::ok($response, [
            'parsed' => count($parsed),
            'created' => count($created),
            'updated' => count($updated),
            'skipped' => count($skipped),
            'nodes' => [...$created, ...$updated],
        ]);
    }

    // ------------------------------------------------------------------
    // 字段归一
    // ------------------------------------------------------------------

    /**
     * 把请求参数写入节点（只写提交了的字段，支持部分更新）。
     *
     * @param array<string, mixed> $p
     */
    private static function apply(Node $node, array $p): void
    {
        foreach ($p as $key => $value) {
            self::applyScalar($node, (string) $key, $value);
        }

        // 动态倍率配置
        if (isset($p['is_dynamic_rate']) || isset($p['max_rate']) || isset($p['min_rate'])) {
            $node->dynamic_rate_config = json_encode([
                'max_rate' => $p['max_rate'] ?? 1,
                'max_rate_time' => $p['max_rate_time'] ?? 22,
                'min_rate' => $p['min_rate'] ?? 1,
                'min_rate_time' => $p['min_rate_time'] ?? 3,
            ]);
        }
    }

    private static function applyScalar(Node $node, string $key, mixed $value): void
    {
        switch ($key) {
            case 'name':
                $t = trim((string) $value);
                if ($t !== '') {
                    $node->name = $t;
                }
                break;
            case 'server':
                $t = trim((string) $value);
                if ($t !== '') {
                    $node->server = $t;
                }
                break;
            case 'sort':
                $node->sort = (int) $value;
                break;
            case 'node_class':
                $node->node_class = (int) $value;
                break;
            case 'node_group':
                $node->node_group = (int) $value;
                break;
            case 'traffic_rate':
                $node->traffic_rate = is_numeric($value) ? (float) $value : 1.0;
                break;
            case 'node_speedlimit':
                $node->node_speedlimit = (int) $value;
                break;
            case 'node_bandwidth_limit':
                // 面板表单以 GB 计
                $node->node_bandwidth_limit = Tools::gbToB(is_numeric($value) ? (float) $value : 0);
                break;
            case 'bandwidthlimit_resetday':
                $node->bandwidthlimit_resetday = (int) $value;
                break;
            case 'type':
                $node->type = self::asBool($value) ? 1 : 0;
                break;
            case 'is_dynamic_rate':
                $node->is_dynamic_rate = self::asBool($value) ? 1 : 0;
                break;
            case 'dynamic_rate_type':
                $node->dynamic_rate_type = (int) $value;
                break;
            case 'custom_config':
                $custom = self::normalizeCustomConfig($value);
                if ($custom !== null) {
                    $node->custom_config = $custom;
                }
                break;
            case 'password':
                $t = trim((string) $value);
                if ($t !== '') {
                    $node->password = $t;
                }
                break;
        }
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private static function normalizeCustomConfig(mixed $value): ?string
    {
        if (is_array($value)) {
            return json_encode($value);
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode($decoded);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function nodeToArray(Node $node): array
    {
        $custom = json_decode((string) $node->custom_config, true);
        if (! is_array($custom)) {
            $custom = [];
        }
        $dynamic = json_decode((string) $node->dynamic_rate_config, true);
        if (! is_array($dynamic)) {
            $dynamic = [];
        }

        return [
            'id' => (int) $node->id,
            'name' => $node->name,
            'server' => $node->server,
            'sort' => (int) $node->sort,
            'type_label' => $node->sort(),
            'type' => (int) $node->type,
            'node_class' => (int) $node->node_class,
            'node_group' => (int) $node->node_group,
            'traffic_rate' => (float) $node->traffic_rate,
            'node_speedlimit' => (int) $node->node_speedlimit,
            'node_bandwidth' => (int) $node->node_bandwidth,
            'node_bandwidth_limit' => (int) $node->node_bandwidth_limit,
            'bandwidthlimit_resetday' => (int) $node->bandwidthlimit_resetday,
            'is_dynamic_rate' => (int) $node->is_dynamic_rate,
            'dynamic_rate_type' => (int) $node->dynamic_rate_type,
            'custom_config' => $custom,
            'dynamic_rate_config' => $dynamic,
            'online_user' => (int) $node->online_user,
            'node_heartbeat' => (int) $node->node_heartbeat,
            'is_online' => $node->getNodeOnlineStatus() === 1,
        ];
    }

    private static function ok(Response $response, mixed $data): ResponseInterface
    {
        return $response->withJson(['ret' => 1, 'data' => $data]);
    }

    private static function fail(Response $response, string $msg): ResponseInterface
    {
        return $response->withJson(['ret' => 0, 'msg' => $msg]);
    }
}