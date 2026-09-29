<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Subscribe;
use App\Services\Xray;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function explode;
use function in_array;
use function json_decode;
use function json_encode;
use function round;
use function time;

/**
 * wumi 商用接口（梯子/付费订阅，P5/P6 数据面）。
 *
 * 架构（2026-09-30 爸爸决策）：SSPanel 是 wumi 的付费订阅扩展，wumi 为唯一
 * 用户体系与出口。本控制器供 wumi 后端以机器密钥（X-Wumi-Api-Key）调用，
 * 代表某 wumi 用户（X-Wumi-User-Key）读写其订阅/订单。
 *
 *   GET  /wumi/api/v1/plans          在售套餐（tabp/bandwidth/time）
 *   GET  /wumi/api/v1/subscription   当前用户订阅（流量/到期/订阅链接/vless 节点）
 *   GET  /wumi/api/v1/orders         当前用户订单
 *   POST /wumi/api/v1/orders         下单（复用与网站一致的商品/优惠码/限购规则）
 *   GET  /wumi/api/v1/admin/overview 收费总览（订阅用户数/订单/收入）
 */
final class CommerceController extends BaseController
{
    /** GET /wumi/api/v1/plans */
    public function plans(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $out = [];
        foreach (['tabp', 'bandwidth', 'time'] as $type) {
            $items = (new Product())->where('status', '1')->where('type', $type)->orderBy('id')->get();
            foreach ($items as $product) {
                $limit = json_decode($product->limit);
                $out[] = [
                    'id' => (int) $product->id,
                    'type' => $product->type,
                    'name' => $product->name,
                    'price' => (float) $product->price,
                    'content' => json_decode($product->content),
                    'stock' => (int) $product->stock,
                    'sale_count' => (int) $product->sale_count,
                    'class_required' => $limit->class_required ?? '',
                    'new_user_required' => $limit->new_user_required ?? 0,
                ];
            }
        }

        return self::ok($response, $out);
    }

    /** GET /wumi/api/v1/subscription */
    public function subscription(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = $this->wumiUser($request);
        if ($user === null) {
            return self::fail($response, '缺少 X-Wumi-User-Key');
        }

        $nodes = [];
        $sub = Subscribe::getUserNodes($user);
        foreach ($sub as $node) {
            $uri = null;
            if ((int) $node->sort === Xray::SORT_VLESS) {
                $uri = Xray::vlessUriForNode($node, (string) $user->uuid);
            }
            $nodes[] = [
                'id' => (int) $node->id,
                'name' => $node->name,
                'server' => $node->server,
                'type_label' => $node->sort(),
                'node_class' => (int) $node->node_class,
                'traffic_rate' => (float) $node->traffic_rate,
                'is_online' => $node->getNodeOnlineStatus() === 1,
                'vless_uri' => $uri,
            ];
        }

        return self::ok($response, [
            'sub_url' => Subscribe::getUniversalSubLink($user),
            'uuid' => $user->uuid,
            'class' => (int) $user->class,
            'class_expire' => $user->class_expire,
            'expired' => strtotime((string) $user->class_expire) < time(),
            'traffic_used' => (int) $user->u + (int) $user->d,
            'traffic_total' => (int) $user->transfer_enable,
            'traffic_used_text' => Tools::autoBytes((int) $user->u + (int) $user->d),
            'traffic_total_text' => Tools::autoBytes((int) $user->transfer_enable),
            'node_speedlimit' => (int) $user->node_speedlimit,
            'node_iplimit' => (int) $user->node_iplimit,
            'nodes' => $nodes,
        ]);
    }

    /** GET /wumi/api/v1/orders */
    public function orders(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = $this->wumiUser($request);
        if ($user === null) {
            return self::fail($response, '缺少 X-Wumi-User-Key');
        }

        $out = [];
        foreach ((new Order())->where('user_id', $user->id)->orderBy('id', 'desc')->limit(50)->get() as $order) {
            $out[] = [
                'id' => (int) $order->id,
                'product_name' => $order->product_name,
                'product_type' => $order->product_type,
                'price' => (float) $order->price,
                'status' => $order->status,
                'create_time' => (int) $order->create_time,
            ];
        }

        return self::ok($response, $out);
    }

    /**
     * POST /wumi/api/v1/orders  body: {product_id, coupon?}
     *
     * 复用与网站下单一致的规则（库存/优惠码/等级/组别/新用户限购），
     * 生成 Order + Invoice；未付费商品直接 pending_activation。
     */
    public function createOrder(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $user = $this->wumiUser($request);
        if ($user === null) {
            return self::fail($response, '缺少 X-Wumi-User-Key');
        }

        $productId = (int) $request->getParam('product_id');
        $couponCode = (string) ($request->getParam('coupon') ?? '');

        $product = (new Product())->find($productId);
        if ($product === null || (int) $product->stock === 0) {
            return self::fail($response, '商品不存在或库存不足');
        }

        if ($user->is_shadow_banned) {
            return self::fail($response, '账户状态异常，无法下单');
        }

        $productLimit = json_decode($product->limit);
        if (($productLimit->class_required ?? '') !== '' && $user->class < (int) $productLimit->class_required) {
            return self::fail($response, '你的账户等级不足，无法购买此商品');
        }
        if (($productLimit->node_group_required ?? '') !== ''
            && $user->node_group !== (int) $productLimit->node_group_required) {
            return self::fail($response, '你所在的用户组无法购买此商品');
        }
        if ((int) ($productLimit->new_user_required ?? 0) !== 0
            && (new Order())->where('user_id', $user->id)->count() > 0) {
            return self::fail($response, '此商品仅限新用户购买');
        }

        $buyPrice = (float) $product->price;
        $discount = 0.0;

        if ($couponCode !== '') {
            $coupon = (new UserCoupon())->where('code', $couponCode)->first();
            if ($coupon === null || ((int) $coupon->expire_time !== 0 && (int) $coupon->expire_time < time())) {
                return self::fail($response, '优惠码不存在或已过期');
            }
            $couponLimit = json_decode($coupon->limit);
            if ($couponLimit->disabled ?? false) {
                return self::fail($response, '优惠码已被禁用');
            }
            if (($couponLimit->product_id ?? '') !== ''
                && ! in_array((string) $productId, explode(',', (string) $couponLimit->product_id))) {
                return self::fail($response, '优惠码不适用于此商品');
            }
            if ((int) ($couponLimit->use_time ?? 0) > 0
                && (new Order())->where('user_id', $user->id)->where('coupon', $coupon->code)->count() >= (int) $couponLimit->use_time) {
                return self::fail($response, '优惠码使用次数已达上限');
            }
            $couponContent = json_decode($coupon->content);
            $discount = ($couponContent->type ?? '') === 'percentage'
                ? $product->price * $couponContent->value / 100
                : (float) $couponContent->value;
            $buyPrice = round((float) $product->price - $discount, 2);
        }

        $order = new Order();
        $order->user_id = $user->id;
        $order->product_id = $product->id;
        $order->product_type = $product->type;
        $order->product_name = $product->name;
        $order->product_content = $product->content;
        $order->coupon = $couponCode;
        $order->price = $buyPrice;
        $order->status = $buyPrice === 0.0 ? 'pending_activation' : 'pending_payment';
        $order->create_time = time();
        $order->update_time = time();
        $order->save();

        $invoice = new Invoice();
        $invoice->user_id = $user->id;
        $invoice->order_id = $order->id;
        $invoice->content = json_encode([[
            'content_id' => 0,
            'name' => $product->name,
            'price' => $product->price,
        ]]);
        $invoice->price = $buyPrice;
        $invoice->status = $buyPrice === 0.0 ? 'paid_gateway' : 'unpaid';
        $invoice->create_time = time();
        $invoice->update_time = time();
        $invoice->pay_time = 0;
        $invoice->type = 'product';
        $invoice->save();

        if ((int) $product->stock > 0) {
            $product->stock = (int) $product->stock - 1;
        }
        $product->sale_count = (int) $product->sale_count + 1;
        $product->save();

        return self::ok($response, [
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'price' => $buyPrice,
            'discount' => $discount,
            'status' => $order->status,
        ]);
    }

    /** GET /wumi/api/v1/admin/overview —— 收费总览 */
    public function overview(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $since = time() - 30 * 86400;

        $orders = (new Order())->get();
        $paidOrders = 0;
        $revenue = 0.0;
        $revenue30d = 0.0;
        $paidUsers = [];

        foreach ($orders as $order) {
            $status = (string) $order->status;
            $isPaid = in_array($status, ['pending_activation', 'activated', 'paid_gateway'], true);
            if ($isPaid) {
                $paidOrders++;
                $revenue += (float) $order->price;
                $paidUsers[(int) $order->user_id] = true;
                if ((int) $order->create_time >= $since) {
                    $revenue30d += (float) $order->price;
                }
            }
        }

        $wumiBound = (new User())->whereNotNull('wumi_user_key')->count();

        return self::ok($response, [
            'paid_user_count' => count($paidUsers),
            'wumi_bound_user_count' => (int) $wumiBound,
            'order_total' => count($orders),
            'order_paid' => $paidOrders,
            'revenue_total' => round($revenue, 2),
            'revenue_30d' => round($revenue30d, 2),
            'product_on_sale' => (new Product())->where('status', '1')->count(),
        ]);
    }

    private function wumiUser(ServerRequest $request): ?User
    {
        $user = $request->getAttribute('user');

        return $user instanceof User ? $user : null;
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