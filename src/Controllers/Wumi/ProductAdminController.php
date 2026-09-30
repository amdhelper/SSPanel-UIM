<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\Product;
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
 * wumi「收费管理 → 商品/价格」写操作。
 *
 * 把 SSPanel 后台「商品管理」的能力（新建/改价/上下架/库存/限购）开放给 wumi
 * 站务管理页；商品类型与 SSPanel 一致：tabp（时间流量包）/ time（时间包）/
 * bandwidth（流量包）。
 *
 *   GET    /wumi/api/v1/products        商品列表（含下架）
 *   POST   /wumi/api/v1/products        新建商品
 *   POST   /wumi/api/v1/products/{id}   修改商品（含改价）
 *   DELETE /wumi/api/v1/products/{id}   删除商品
 *
 * ⚠️ 路径**不得包含 `/admin`**。
 */
final class ProductAdminController extends BaseController
{
    public const TYPES = ['tabp', 'time', 'bandwidth'];

    /** GET /wumi/api/v1/products */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $out = [];
        foreach ((new Product())->orderBy('id')->get() as $product) {
            $out[] = self::toArray($product);
        }

        return self::ok($response, $out);
    }

    /** POST /wumi/api/v1/products */
    public function create(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $params = $request->getParams();

        $type = trim((string) ($params['type'] ?? ''));
        if (! in_array($type, self::TYPES, true)) {
            return self::fail($response, '商品类型非法（应为 tabp / time / bandwidth）');
        }

        $name = trim((string) ($params['name'] ?? ''));
        if ($name === '') {
            return self::fail($response, '商品名称不能为空');
        }

        $price = (float) ($params['price'] ?? 0);
        if ($price < 0) {
            return self::fail($response, '价格不能为负');
        }

        $built = self::buildContent($type, $params);
        if ($built === null) {
            return self::fail($response, '商品内容不合法：时间/流量/等级时长必须大于 0');
        }

        $product = new Product();
        $product->type = $type;
        $product->name = $name;
        $product->price = $price;
        $product->content = json_encode($built['content']);
        $product->limit = json_encode(self::buildLimit($params));
        $product->status = self::asInt($params['status'] ?? 1);
        $product->stock = (int) ($params['stock'] ?? -1);
        $product->sale_count = 0;
        $product->create_time = time();
        $product->update_time = time();
        $product->save();

        return self::ok($response, ['product_id' => (int) $product->id, 'product' => self::toArray($product)]);
    }

    /** POST /wumi/api/v1/products/{id} */
    public function update(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $product = (new Product())->find($args['id']);
        if ($product === null) {
            return self::fail($response, '商品不存在');
        }

        $params = $request->getParams();

        $type = trim((string) ($params['type'] ?? $product->type));
        if (! in_array($type, self::TYPES, true)) {
            return self::fail($response, '商品类型非法（应为 tabp / time / bandwidth）');
        }

        // 名称 / 价格 / 库存 / 状态
        if (isset($params['name'])) {
            $name = trim((string) $params['name']);
            if ($name === '') {
                return self::fail($response, '商品名称不能为空');
            }
            $product->name = $name;
        }
        if (isset($params['price'])) {
            $price = (float) $params['price'];
            if ($price < 0) {
                return self::fail($response, '价格不能为负');
            }
            $product->price = $price;
        }
        if (isset($params['status'])) {
            $product->status = self::asInt($params['status']);
        }
        if (isset($params['stock'])) {
            $product->stock = (int) $params['stock'];
        }

        // 内容与限购：给了任一内容字段才重建
        $hasContent = false;
        foreach (['time', 'bandwidth', 'class', 'class_time', 'node_group', 'speed_limit', 'ip_limit'] as $k) {
            if (isset($params[$k])) {
                $hasContent = true;
                break;
            }
        }
        $merged = self::mergeContent($product, $params);
        if ($hasContent || $type !== $product->type) {
            $built = self::buildContent($type, $merged);
            if ($built === null) {
                return self::fail($response, '商品内容不合法：时间/流量/等级时长必须大于 0');
            }
            $product->content = json_encode($built['content']);
        }
        $product->type = $type;

        if (isset($params['class_required']) || isset($params['node_group_required']) || isset($params['new_user_required'])) {
            $product->limit = json_encode(self::buildLimit($merged));
        }

        $product->update_time = time();
        $product->save();

        return self::ok($response, ['product' => self::toArray($product)]);
    }

    /** DELETE /wumi/api/v1/products/{id} */
    public function delete(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $product = (new Product())->find($args['id']);
        if ($product === null) {
            return self::fail($response, '商品不存在');
        }

        if (! $product->delete()) {
            return self::fail($response, '删除失败');
        }

        return self::ok($response, ['deleted' => true, 'product_id' => (int) $args['id']]);
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed> $p
     * @return array{content: array<string, mixed>}|null
     */
    private static function buildContent(string $type, array $p): ?array
    {
        $time = (int) ($p['time'] ?? 0);
        $bandwidth = (int) ($p['bandwidth'] ?? 0);
        $classTime = (int) ($p['class_time'] ?? 0);

        return match ($type) {
            'tabp' => ($time <= 0 || $classTime <= 0 || $bandwidth <= 0) ? null : [
                'content' => [
                    'time' => $time,
                    'bandwidth' => $bandwidth,
                    'class' => (int) ($p['class'] ?? 0),
                    'class_time' => $classTime,
                    'node_group' => (int) ($p['node_group'] ?? 0),
                    'speed_limit' => (int) ($p['speed_limit'] ?? 0),
                    'ip_limit' => (int) ($p['ip_limit'] ?? 0),
                ],
            ],
            'time' => ($time <= 0 || $classTime <= 0) ? null : [
                'content' => [
                    'time' => $time,
                    'class' => (int) ($p['class'] ?? 0),
                    'class_time' => $classTime,
                    'node_group' => (int) ($p['node_group'] ?? 0),
                    'speed_limit' => (int) ($p['speed_limit'] ?? 0),
                    'ip_limit' => (int) ($p['ip_limit'] ?? 0),
                ],
            ],
            'bandwidth' => $bandwidth <= 0 ? null : [
                'content' => ['bandwidth' => $bandwidth],
            ],
            default => null,
        };
    }

    /**
     * 用商品现有 content 兜底未提交字段。
     *
     * @param  array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function mergeContent(Product $product, array $p): array
    {
        $old = json_decode((string) $product->content, true);
        if (! is_array($old)) {
            $old = [];
        }
        $oldLimit = json_decode((string) $product->limit, true);
        if (! is_array($oldLimit)) {
            $oldLimit = [];
        }

        foreach (['time', 'bandwidth', 'class', 'class_time', 'node_group', 'speed_limit', 'ip_limit'] as $k) {
            if (! isset($p[$k]) && isset($old[$k])) {
                $p[$k] = $old[$k];
            }
        }
        foreach (['class_required', 'node_group_required', 'new_user_required'] as $k) {
            if (! isset($p[$k]) && isset($oldLimit[$k])) {
                $p[$k] = $oldLimit[$k];
            }
        }

        return $p;
    }

    /**
     * @param  array<string, mixed> $p
     * @return array<string, mixed>
     */
    private static function buildLimit(array $p): array
    {
        return [
            'class_required' => (string) ($p['class_required'] ?? ''),
            'node_group_required' => (string) ($p['node_group_required'] ?? ''),
            'new_user_required' => self::asInt($p['new_user_required'] ?? 0),
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
    private static function toArray(Product $product): array
    {
        $content = json_decode((string) $product->content, true);
        $limit = json_decode((string) $product->limit, true);

        return [
            'id' => (int) $product->id,
            'type' => $product->type,
            'type_label' => $product->type(),
            'name' => $product->name,
            'price' => (float) $product->price,
            'status' => (int) $product->status,
            'status_label' => $product->status(),
            'stock' => (int) $product->stock,
            'sale_count' => (int) $product->sale_count,
            'content' => is_array($content) ? $content : [],
            'limit' => is_array($limit) ? $limit : [],
            'create_time' => (int) $product->create_time,
            'update_time' => (int) $product->update_time,
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