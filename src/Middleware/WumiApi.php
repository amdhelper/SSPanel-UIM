<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Services\Wumi\Identity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use function hash_equals;
use function json_encode;

/**
 * wumi 内部 API 鉴权中间件。
 *
 * 供 wumi Go 后端（或本站脚本）以机器密钥调用，代表某个 wumi 用户操作：
 * - 头 `X-Wumi-Api-Key`：共享机器密钥（Config: wumi_api_key）
 * - 头 `X-Wumi-User-Key`：可选；给定时按公钥解析/自动开户并注入 `user` 属性
 */
final class WumiApi implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $expected = Identity::apiKey();
        $given = $request->getHeaderLine('X-Wumi-Api-Key');

        if ($expected === '' || $given === '' || ! hash_equals($expected, $given)) {
            $response = AppFactory::determineResponseFactory()->createResponse(401);
            $response->getBody()->write(json_encode(['ret' => 0, 'msg' => '无效的 wumi 机器密钥']));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $userKey = $request->getHeaderLine('X-Wumi-User-Key');

        if ($userKey !== '') {
            $request = $request->withAttribute('wumi_user_key', $userKey);
            $request = $request->withAttribute('user', Identity::resolveUser($userKey));
        }

        return $handler->handle($request);
    }
}