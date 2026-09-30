<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\UserCoupon;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function in_array;
use function is_array;
use function is_bool;
use function json_decode;
use function json_encode;
use function time;
use function trim;

/**
 * wumi「收费管理 → 优惠码」读写。
 *
 * 复刻 SSPanel 后台 `Admin\CouponController` 的能力：新建（指定字符 / 随机 / 指定+随机）、
 * 列表、修改、禁用、删除。优惠码在用户下单时由 SSPanel 侧校验（商品限定/次数/新用户）。
 *
 *   GET    /wumi/api/v1/coupons              优惠码列表
 *   POST   /wumi/api/v1/coupons              新建优惠码
 *   POST   /wumi/api/v1/coupons/{id}         修改优惠码
 *   POST   /wumi/api/v1/coupons/{id}/disable 禁用
 *   DELETE /wumi/api/v1/coupons/{id}         删除
 *
 * ⚠️ 路径**不得包含 `/admin`**。
 */
final class CouponAdminController extends BaseController
{
    /** GET /wumi/api/v1/coupons */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $out = [];
        foreach ((new UserCoupon())->orderBy('id', 'desc')->get() as $coupon) {
            $out[] = self::toArray($coupon);
        }

        return self::ok($response, $out);
    }

    /** POST /wumi/api/v1/coupons */
    public function create(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $params = $request->getParams();

        $type = trim((string) ($params['type'] ?? ''));
        $value = (float) ($params['value'] ?? 0);
        if (! in_array($type, ['percentage', 'fixed'], true)) {
            return self::fail($response, '优惠码类型非法（percentage 百分比 / fixed 固定金额）');
        }
        if ($value <= 0) {
            return self::fail($response, '优惠码额度必须大于 0');
        }

        $generateMethod = (string) ($params['generate_method'] ?? 'char');
        $code = trim((string) ($params['code'] ?? ''));

        if ($code === '' && $generateMethod !== 'random') {
            return self::fail($response, '优惠码不能为空');
        }
        if ($generateMethod === 'char' && (new UserCoupon())->where('code', $code)->count() !== 0) {
            return self::fail($response, '优惠码已存在');
        }
        if ($generateMethod === 'char_random') {
            $code .= Tools::genRandomChar();
        }
        if ($generateMethod === 'random') {
            $code = Tools::genRandomChar();
        }
        if ((new UserCoupon())->where('code', $code)->count() !== 0) {
            return self::fail($response, '优惠码生成冲突，请重试');
        }

        $expireTime = $params['expire_time'] ?? 0;
        if ($expireTime !== 0 && $expireTime !== '' && (int) $expireTime < time()) {
            return self::fail($response, '过期时间必须晚于当前时间');
        }

        $coupon = new UserCoupon();
        $coupon->code = $code;
        $coupon->content = json_encode(['type' => $type, 'value' => $value]);
        $coupon->limit = json_encode(self::buildLimit($params, []));
        $coupon->create_time = time();
        $coupon->expire_time = ($expireTime === '' || $expireTime === null) ? 0 : (int) $expireTime;
        $coupon->save();

        return self::ok($response, ['coupon' => self::toArray($coupon)]);
    }

    /** POST /wumi/api/v1/coupons/{id} */
    public function update(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $coupon = (new UserCoupon())->find($args['id']);
        if ($coupon === null) {
            return self::fail($response, '优惠码不存在');
        }

        $params = $request->getParams();

        $content = json_decode((string) $coupon->content, true);
        if (! is_array($content)) {
            $content = [];
        }
        $limit = json_decode((string) $coupon->limit, true);
        if (! is_array($limit)) {
            $limit = [];
        }

        if (isset($params['type'])) {
            if (! in_array((string) $params['type'], ['percentage', 'fixed'], true)) {
                return self::fail($response, '优惠码类型非法');
            }
            $content['type'] = (string) $params['type'];
        }
        if (isset($params['value'])) {
            $value = (float) $params['value'];
            if ($value <= 0) {
                return self::fail($response, '优惠码额度必须大于 0');
            }
            $content['value'] = $value;
        }
        if (isset($params['code'])) {
            $code = trim((string) $params['code']);
            if ($code === '') {
                return self::fail($response, '优惠码不能为空');
            }
            $dup = (new UserCoupon())->where('code', $code)->where('id', '!=', $coupon->id)->count();
            if ($dup !== 0) {
                return self::fail($response, '优惠码已存在');
            }
            $coupon->code = $code;
        }
        if (isset($params['expire_time'])) {
            $coupon->expire_time = (int) $params['expire_time'];
        }

        $coupon->content = json_encode($content);
        $coupon->limit = json_encode(self::buildLimit($params, $limit));
        $coupon->save();

        return self::ok($response, ['coupon' => self::toArray($coupon)]);
    }

    /** POST /wumi/api/v1/coupons/{id}/disable */
    public function disable(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $coupon = (new UserCoupon())->find($args['id']);
        if ($coupon === null) {
            return self::fail($response, '优惠码不存在');
        }

        $limit = json_decode((string) $coupon->limit, true);
        if (! is_array($limit)) {
            $limit = [];
        }
        // 允许传 disabled=false 反向启用
        $disabled = (bool) $request->getParam('disabled', true);
        $limit['disabled'] = $disabled ? 1 : 0;
        $coupon->limit = json_encode($limit);
        $coupon->save();

        return self::ok($response, ['coupon' => self::toArray($coupon)]);
    }

    /** DELETE /wumi/api/v1/coupons/{id} */
    public function delete(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $coupon = (new UserCoupon())->find($args['id']);
        if ($coupon === null) {
            return self::fail($response, '优惠码不存在');
        }
        $coupon->delete();

        return self::ok($response, ['deleted' => true, 'coupon_id' => (int) $args['id']]);
    }

    // ------------------------------------------------------------------

    /**
     * 合并 limit（未提交字段沿用旧值，disabled 默认 0）。
     *
     * @param  array<string, mixed> $params
     * @param  array<string, mixed> $old
     * @return array<string, mixed>
     */
    private static function buildLimit(array $params, array $old): array
    {
        $pick = static function (string $key, mixed $default) use ($params, $old): mixed {
            if (isset($params[$key])) {
                return $params[$key];
            }

            return $old[$key] ?? $default;
        };

        return [
            'product_id' => (string) $pick('product_id', ''),
            'use_time' => (int) $pick('use_time', -1),
            'total_use_time' => (int) $pick('total_use_time', -1),
            'new_user' => self::asInt($pick('new_user', 0)),
            'disabled' => self::asInt($pick('disabled', 0)),
        ];
    }

    private static function asInt(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return (int) $value;
    }

    /** @return array<string, mixed> */
    private static function toArray(UserCoupon $coupon): array
    {
        $content = json_decode((string) $coupon->content, true);
        if (! is_array($content)) {
            $content = [];
        }
        $limit = json_decode((string) $coupon->limit, true);
        if (! is_array($limit)) {
            $limit = [];
        }

        $useTime = array_key_exists('use_time', $limit) ? (int) $limit['use_time'] : -1;
        $totalUseTime = array_key_exists('total_use_time', $limit) ? (int) $limit['total_use_time'] : -1;
        $expire = (int) $coupon->expire_time;

        return [
            'id' => (int) $coupon->id,
            'code' => $coupon->code,
            'type' => $content['type'] ?? '',
            'type_label' => $content['type'] === 'percentage' ? '百分比' : '固定金额',
            'value' => (float) ($content['value'] ?? 0),
            'product_id' => (string) ($limit['product_id'] ?? ''),
            'use_time' => $useTime,
            'use_time_unlimited' => $useTime < 0,
            'total_use_time' => $totalUseTime,
            'total_use_time_unlimited' => $totalUseTime < 0,
            'new_user' => (int) ($limit['new_user'] ?? 0),
            'disabled' => (int) ($limit['disabled'] ?? 0),
            'use_count' => (int) $coupon->use_count,
            'create_time' => (int) $coupon->create_time,
            'expire_time' => $expire,
            'expire_text' => $expire === 0 ? '永久有效' : Tools::toDateTime($expire),
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