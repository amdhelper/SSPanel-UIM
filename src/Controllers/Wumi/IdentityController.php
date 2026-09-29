<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\User;
use App\Services\Auth;
use App\Services\Wumi\Identity;
use App\Utils\Cookie;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function json_encode;
use function time;
use function trim;

/**
 * wumi 身份桥控制器。
 *
 * - GET /wumi/sso?token=<wumi access JWT>：wumi 侧发起单点登录，校验后建立 SSPanel 会话
 * - GET /wumi/api/v1/me：机器密钥鉴权，返回绑定用户信息（供 wumi 后端代理）
 */
final class IdentityController extends BaseController
{
    /** wumi → SSPanel 单点登录 */
    public function sso(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $token = trim((string) $request->getParam('token'));
        if ($token === '') {
            $token = trim($request->getHeaderLine('Authorization'));
            $token = str_starts_with($token, 'Bearer ') ? substr($token, 7) : $token;
        }

        $publicKey = $token === '' ? null : Identity::publicKeyFromToken($token);

        if ($publicKey === null) {
            return $response->withHeader('Location', '/auth/login');
        }

        $user = Identity::resolveUser($publicKey);

        if ($user->is_banned) {
            return $response->withHeader('Location', '/user/banned');
        }

        Auth::login($user->id, 3600);

        $redir = Cookie::get('redir');
        if ($redir === '') {
            $redir = '/user';
        }

        return $response->withHeader('Location', $redir);
    }

    /** GET /wumi/api/v1/me — 返回当前绑定用户 */
    public function me(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (! $user instanceof User) {
            return $response->withJson(['ret' => 0, 'msg' => '缺少 X-Wumi-User-Key']);
        }

        return $response->withJson([
            'ret' => 1,
            'data' => [
                'id' => (int) $user->id,
                'user_name' => $user->user_name,
                'email' => $user->email,
                'wumi_user_key' => $user->wumi_user_key,
                'class' => (int) $user->class,
                'class_expire' => $user->class_expire,
                'money' => (float) $user->money,
                'u' => (int) $user->u,
                'd' => (int) $user->d,
                'transfer_enable' => (int) $user->transfer_enable,
                'transfer_used' => (int) $user->u + (int) $user->d,
                'node_speedlimit' => (int) $user->node_speedlimit,
                'node_iplimit' => (int) $user->node_iplimit,
                'is_banned' => (bool) $user->is_banned,
                'last_login_time' => (int) $user->last_login_time,
                'synced_at' => time(),
            ],
        ]);
    }
}