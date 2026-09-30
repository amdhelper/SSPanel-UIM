<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\User;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function date;
use function in_array;
use function intdiv;
use function is_bool;
use function is_numeric;
use function is_string;
use function max;
use function round;
use function strtotime;
use function time;
use function trim;

/**
 * wumi「用户管理 → SSPanel 属性」读写。
 *
 * 把 SSPanel 用户的商用/订阅属性开放给 wumi 站务管理页：等级（class）、
 * 到期时间、可用流量、限速、同时在线 IP、节点分组、封禁、备注、重置流量。
 *
 *   GET    /wumi/api/v1/users               用户列表（关键词/分页）
 *   GET    /wumi/api/v1/users/{id}          单个用户详情
 *   POST   /wumi/api/v1/users/{id}          修改 SSPanel 属性
 *   POST   /wumi/api/v1/users/{id}/reset-traffic  重置已用流量
 *
 * ⚠️ 路径**不得包含 `/admin`**。
 */
final class UserAdminController extends BaseController
{
    private const MAX_PAGE_SIZE = 200;

    /** GET /wumi/api/v1/users */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $keyword = trim((string) $request->getParam('keyword', ''));
        $page = max(1, (int) $request->getParam('page', 1));
        $size = max(1, (int) $request->getParam('size', 30));
        if ($size > self::MAX_PAGE_SIZE) {
            $size = self::MAX_PAGE_SIZE;
        }

        $query = new User();

        if ($keyword !== '') {
            $query = $query->where(static function ($q) use ($keyword): void {
                $q->where('user_name', 'like', '%' . $keyword . '%')
                    ->orWhere('email', 'like', '%' . $keyword . '%')
                    ->orWhere('wumi_user_key', 'like', '%' . $keyword . '%');
                if (is_numeric($keyword)) {
                    $q->orWhere('id', (int) $keyword);
                }
            });
        }

        $total = (int) $query->count();
        $users = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $out = [];
        foreach ($users as $user) {
            $out[] = self::toArray($user);
        }

        return self::ok($response, [
            'total' => (int) $total,
            'page' => $page,
            'size' => $size,
            'pages' => $total === 0 ? 0 : intdiv((int) $total + $size - 1, $size),
            'users' => $out,
        ]);
    }

    /** GET /wumi/api/v1/users/{id} */
    public function show(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = (new User())->find($args['id']);
        if ($user === null) {
            return self::fail($response, '用户不存在');
        }

        return self::ok($response, self::toArray($user));
    }

    /** POST /wumi/api/v1/users/{id} */
    public function update(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = (new User())->find($args['id']);
        if ($user === null) {
            return self::fail($response, '用户不存在');
        }

        $params = $request->getParams();

        if (isset($params['class'])) {
            $user->class = (int) $params['class'];
        }
        if (isset($params['class_expire'])) {
            $expire = self::parseExpire($params['class_expire']);
            if ($expire !== null) {
                $user->class_expire = $expire;
            }
        }
        if (isset($params['expire_days'])) {
            $days = (int) $params['expire_days'];
            $user->class_expire = date('Y-m-d H:i:s', time() + $days * 86400);
        }
        if (isset($params['transfer_enable'])) {
            // 面板以 GB 计
            $user->transfer_enable = Tools::gbToB(is_numeric($params['transfer_enable']) ? (float) $params['transfer_enable'] : 0);
        }
        if (isset($params['node_speedlimit'])) {
            $user->node_speedlimit = (int) $params['node_speedlimit'];
        }
        if (isset($params['node_iplimit'])) {
            $user->node_iplimit = (int) $params['node_iplimit'];
        }
        if (isset($params['node_group'])) {
            $user->node_group = (int) $params['node_group'];
        }
        if (isset($params['is_banned'])) {
            $user->is_banned = self::asBool($params['is_banned']) ? 1 : 0;
        }
        if (isset($params['banned_reason'])) {
            $user->banned_reason = (string) $params['banned_reason'];
        }
        if (isset($params['is_shadow_banned'])) {
            $user->is_shadow_banned = self::asBool($params['is_shadow_banned']) ? 1 : 0;
        }
        if (isset($params['remark'])) {
            $user->remark = (string) $params['remark'];
        }
        if (isset($params['method'])) {
            $user->method = (string) $params['method'];
        }
        if (isset($params['money'])) {
            $user->money = (float) $params['money'];
        }

        $user->save();

        return self::ok($response, self::toArray($user));
    }

    /** POST /wumi/api/v1/users/{id}/reset-traffic */
    public function resetTraffic(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = (new User())->find($args['id']);
        if ($user === null) {
            return self::fail($response, '用户不存在');
        }

        $user->u = 0;
        $user->d = 0;
        $user->transfer_today = 0;
        $user->save();

        return self::ok($response, self::toArray($user));
    }

    // ------------------------------------------------------------------

    private static function parseExpire(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '' && ! is_numeric($value)) {
            $ts = strtotime($value);

            return $ts === false ? null : date('Y-m-d H:i:s', $ts);
        }

        if (is_numeric($value)) {
            $num = (int) $value;
            // 小于 100000 视为「天数增量」，否则视为时间戳
            $ts = $num > 100000 ? $num : time() + $num * 86400;

            return date('Y-m-d H:i:s', $ts);
        }

        return null;
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(trim((string) $value), ['true', 'yes', 'on', '1'], true);
    }

    /** @return array<string, mixed> */
    private static function toArray(User $user): array
    {
        $used = (int) $user->u + (int) $user->d;
        $total = (int) $user->transfer_enable;
        $expireTs = strtotime((string) $user->class_expire);

        return [
            'id' => (int) $user->id,
            'user_name' => $user->user_name,
            'email' => $user->email,
            'wumi_user_key' => $user->wumi_user_key,
            'wumi_bound' => trim((string) $user->wumi_user_key) !== '',
            'class' => (int) $user->class,
            'class_expire' => (string) $user->class_expire,
            'expired' => $expireTs !== false && $expireTs < time(),
            'transfer_enable' => $total,
            'transfer_enable_gb' => round(Tools::bToGB($total), 3),
            'traffic_used' => $used,
            'traffic_used_text' => Tools::autoBytes($used),
            'traffic_total_text' => Tools::autoBytes($total),
            'node_speedlimit' => (int) $user->node_speedlimit,
            'node_iplimit' => (int) $user->node_iplimit,
            'node_group' => (int) $user->node_group,
            'is_banned' => (int) $user->is_banned,
            'banned_reason' => (string) $user->banned_reason,
            'is_shadow_banned' => (int) $user->is_shadow_banned,
            'money' => (float) $user->money,
            'remark' => (string) $user->remark,
            'method' => (string) $user->method,
            'reg_date' => (string) $user->reg_date,
            'last_login_time' => (int) $user->last_login_time,
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