<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function in_array;
use function intdiv;
use function is_numeric;
use function max;
use function time;
use function trim;

/**
 * wumi「收费管理」订单/账单读写。
 *
 * 把 SSPanel 后台「订单管理 / 账单管理」的核心动作开放给 wumi 站务管理页：
 * 查看全部订单、取消/删除订单、查看账单、标记账单已支付（→ 订单转待激活）。
 *
 *   GET    /wumi/api/v1/billing/orders               订单列表
 *   POST   /wumi/api/v1/billing/orders/{id}/cancel   取消订单
 *   POST   /wumi/api/v1/billing/orders/{id}/activate 标记订单待激活
 *   DELETE /wumi/api/v1/billing/orders/{id}          删除订单
 *   GET    /wumi/api/v1/billing/invoices             账单列表
 *   POST   /wumi/api/v1/billing/invoices/{id}/mark-paid  标记账单已支付
 *
 * ⚠️ 路径**不得包含 `/admin`**。
 */
final class BillingAdminController extends BaseController
{
    private const MAX_PAGE_SIZE = 200;

    /** GET /wumi/api/v1/billing/orders */
    public function orders(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $status = trim((string) $request->getParam('status', ''));
        $keyword = trim((string) $request->getParam('keyword', ''));
        $page = max(1, (int) $request->getParam('page', 1));
        $size = self::clampSize((int) $request->getParam('size', 30));

        $query = new Order();
        if ($status !== '') {
            $query = $query->where('status', $status);
        }
        if ($keyword !== '') {
            $query = $query->where(static function ($q) use ($keyword): void {
                $q->where('product_name', 'like', '%' . $keyword . '%')
                    ->orWhere('id', is_numeric($keyword) ? (int) $keyword : 0);
            });
        }

        $total = (int) $query->count();
        $orders = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $names = [];
        $out = [];
        foreach ($orders as $order) {
            $uid = (int) $order->user_id;
            if (! isset($names[$uid])) {
                $user = (new User())->find($uid);
                $names[$uid] = $user === null ? '' : $user->user_name;
            }
            $out[] = [
                'id' => (int) $order->id,
                'user_id' => $uid,
                'user_name' => $names[$uid],
                'product_id' => (int) $order->product_id,
                'product_name' => $order->product_name,
                'product_type' => $order->product_type,
                'product_type_label' => $order->productType(),
                'price' => (float) $order->price,
                'coupon' => $order->coupon,
                'status' => $order->status,
                'status_label' => $order->status(),
                'create_time' => (int) $order->create_time,
                'update_time' => (int) $order->update_time,
            ];
        }

        return self::ok($response, [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'pages' => $total === 0 ? 0 : intdiv($total + $size - 1, $size),
            'orders' => $out,
        ]);
    }

    /** POST /wumi/api/v1/billing/orders/{id}/cancel */
    public function cancelOrder(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $order = (new Order())->find($args['id']);
        if ($order === null) {
            return self::fail($response, '订单不存在');
        }

        if (in_array($order->status, ['cancelled', 'activated'], true)) {
            return self::fail($response, '该订单已取消或已激活，无法取消');
        }

        $order->status = 'cancelled';
        $order->update_time = time();
        $order->save();

        return self::ok($response, ['order_id' => (int) $order->id, 'status' => $order->status]);
    }

    /** POST /wumi/api/v1/billing/orders/{id}/activate */
    public function activateOrder(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $order = (new Order())->find($args['id']);
        if ($order === null) {
            return self::fail($response, '订单不存在');
        }

        if ($order->status === 'cancelled') {
            return self::fail($response, '已取消订单无法激活');
        }

        $order->status = 'pending_activation';
        $order->update_time = time();
        $order->save();

        return self::ok($response, ['order_id' => (int) $order->id, 'status' => $order->status]);
    }

    /** DELETE /wumi/api/v1/billing/orders/{id} */
    public function deleteOrder(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $order = (new Order())->find($args['id']);
        if ($order === null) {
            return self::fail($response, '订单不存在');
        }

        if (! $order->delete()) {
            return self::fail($response, '删除失败');
        }

        return self::ok($response, ['deleted' => true, 'order_id' => (int) $args['id']]);
    }

    /** GET /wumi/api/v1/billing/invoices */
    public function invoices(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $status = trim((string) $request->getParam('status', ''));
        $page = max(1, (int) $request->getParam('page', 1));
        $size = self::clampSize((int) $request->getParam('size', 30));

        $query = new Invoice();
        if ($status !== '') {
            $query = $query->where('status', $status);
        }

        $total = (int) $query->count();
        $invoices = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $out = [];
        foreach ($invoices as $invoice) {
            $out[] = [
                'id' => (int) $invoice->id,
                'user_id' => (int) $invoice->user_id,
                'order_id' => (int) $invoice->order_id,
                'type' => $invoice->type,
                'price' => (float) $invoice->price,
                'status' => $invoice->status,
                'status_label' => $invoice->status(),
                'create_time' => (int) $invoice->create_time,
                'update_time' => (int) $invoice->update_time,
                'pay_time' => (int) $invoice->pay_time,
            ];
        }

        return self::ok($response, [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'pages' => $total === 0 ? 0 : intdiv($total + $size - 1, $size),
            'invoices' => $out,
        ]);
    }

    /** POST /wumi/api/v1/billing/invoices/{id}/mark-paid */
    public function markPaid(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $invoice = (new Invoice())->find($args['id']);
        if ($invoice === null) {
            return self::fail($response, '账单不存在');
        }

        if (in_array($invoice->status, ['paid_gateway', 'paid_balance', 'paid_admin'], true)) {
            return self::fail($response, '不能标记已经支付的账单');
        }

        $order = (new Order())->find($invoice->order_id);
        if ($order === null) {
            return self::fail($response, '关联订单不存在');
        }
        if ($order->status === 'cancelled') {
            return self::fail($response, '关联订单已被取消，标记失败');
        }

        $order->status = 'pending_activation';
        $order->update_time = time();
        $order->save();

        $invoice->status = 'paid_admin';
        $invoice->pay_time = time();
        $invoice->update_time = time();
        $invoice->save();

        return self::ok($response, [
            'invoice_id' => (int) $invoice->id,
            'order_id' => (int) $order->id,
            'status' => $invoice->status,
        ]);
    }

    private static function clampSize(int $size): int
    {
        if ($size < 1) {
            return 30;
        }

        return $size > self::MAX_PAGE_SIZE ? self::MAX_PAGE_SIZE : $size;
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