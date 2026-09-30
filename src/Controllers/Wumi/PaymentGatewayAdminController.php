<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\Config;
use App\Services\Payment;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function array_map;
use function array_values;
use function in_array;
use function is_array;
use function preg_match;
use function strval;
use function trim;

/**
 * wumi「收费管理 → 支付网关」配置读写。
 *
 * 复刻 SSPanel 后台「系统设置 → 财务」的核心：启用/停用支付网关（易支付/Stripe/
 * PayPal/当面付/Cryptomus/Smogate）+ 对应参数。
 *
 * 安全约定（本控制器独有）：
 *   - `is_public=0` 且名称含 key/secret/password/token 的项视为**密钥**：
 *     读取只回传 `configured`（是否已配置），**绝不回传明文**；
 *     写入时若传空串或掩码占位符则跳过，避免误清空既有密钥。
 *   - 只允许写 class=billing 的配置项，别的类一律拒绝。
 *
 *   GET  /wumi/api/v1/billing/gateways   网关清单 + 财务配置（密钥掩码）
 *   POST /wumi/api/v1/billing/gateways   保存勾选的网关与配置项
 *
 * ⚠️ 路径**不得包含 `/admin`**。
 */
final class PaymentGatewayAdminController extends BaseController
{
    /** 密钥项识别（值永不回传） */
    private const SECRET_PATTERN = '/(key|secret|password|token)/i';

    /** GET /wumi/api/v1/billing/gateways */
    public function show(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        return self::ok($response, [
            'gateways' => self::gatewayList(),
            'settings' => self::settings(),
        ]);
    }

    /** POST /wumi/api/v1/billing/gateways  body: {active:[slug...], settings:{item:value}} */
    public function save(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $active = $request->getParam('active');
        if ($active !== null) {
            if (! is_array($active)) {
                return self::fail($response, 'active 必须是网关 slug 数组');
            }
            $slugs = array_values(array_map(strval(...), $active));
            $known = array_map(static fn (array $g): string => $g['slug'], self::gatewayList());
            foreach ($slugs as $slug) {
                if (! in_array($slug, $known, true)) {
                    return self::fail($response, '未知支付网关: ' . $slug);
                }
            }
            if (! Config::set('payment_gateway', $slugs)) {
                return self::fail($response, '保存支付网关时出错');
            }
        }

        $settings = $request->getParam('settings');
        if ($settings !== null) {
            if (! is_array($settings)) {
                return self::fail($response, 'settings 必须是对象');
            }
            $allowed = self::allowedItems();
            foreach ($settings as $item => $value) {
                $item = (string) $item;
                if ($item === 'payment_gateway') {
                    continue;
                }
                if (! in_array($item, $allowed, true)) {
                    return self::fail($response, '不允许修改配置项: ' . $item);
                }
                // 密钥项：空值/掩码占位符 = 不改动
                if (self::isSecret($item)) {
                    $text = trim((string) $value);
                    if ($text === '' || $text === '••••••' || $text === '******') {
                        continue;
                    }
                }
                if (! Config::set($item, $value)) {
                    return self::fail($response, '保存 ' . $item . ' 时出错');
                }
            }
        }

        return self::ok($response, [
            'gateways' => self::gatewayList(),
            'settings' => self::settings(),
        ]);
    }

    // ------------------------------------------------------------------

    /** @return array<int, array<string, mixed>> */
    private static function gatewayList(): array
    {
        $active = Config::obtain('payment_gateway');
        if (! is_array($active)) {
            $active = [];
        }

        $out = [];
        foreach (Payment::getAllPaymentMap() as $class) {
            $slug = (string) $class::_name();
            $out[] = [
                'slug' => $slug,
                'label' => (string) $class::_readableName(),
                'active' => in_array($slug, $active, true),
            ];
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private static function settings(): array
    {
        $out = [];
        foreach ((new Config())->where('class', 'billing')->orderBy('id')->get() as $config) {
            $item = (string) $config->item;
            if ($item === 'payment_gateway') {
                continue;
            }
            $secret = self::isSecret($item);
            $value = (string) $config->value;

            $out[] = [
                'item' => $item,
                'mark' => (string) $config->mark,
                'type' => (string) $config->type,
                'is_public' => (int) $config->is_public === 1 ? 1 : 0,
                'secret' => $secret,
                'configured' => trim($value) !== '' && trim($value) !== '[]',
                'value' => $secret ? '' : $value,
            ];
        }

        return $out;
    }

    private static function isSecret(string $item): bool
    {
        if (preg_match(self::SECRET_PATTERN, $item) !== 1) {
            return false;
        }

        return (int) ((new Config())->where('item', $item)->value('is_public') ?? 1) === 0;
    }

    /** @return array<int, string> */
    private static function allowedItems(): array
    {
        $out = [];
        foreach ((new Config())->where('class', 'billing')->get() as $config) {
            $out[] = (string) $config->item;
        }

        return $out;
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