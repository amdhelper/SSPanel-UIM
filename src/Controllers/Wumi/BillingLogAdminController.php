<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\Payback;
use App\Models\Paylist;
use App\Models\User;
use App\Models\UserMoneyLog;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function intdiv;
use function max;

/**
 * wumi「收费管理 → 流水」只读接口（对账用）。
 *
 *   GET /wumi/api/v1/billing/paybacks    邀请返利记录
 *   GET /wumi/api/v1/billing/paylists    网关交易流水
 *   GET /wumi/api/v1/billing/money-logs  用户余额变动流水
 *
 * ⚠️ 路径**不得包含 `/admin`**。
 */
final class BillingLogAdminController extends BaseController
{
    private const MAX_PAGE_SIZE = 200;

    /** GET /wumi/api/v1/billing/paybacks */
    public function paybacks(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        [$page, $size] = self::paging($request);

        $query = new Payback();
        $total = (int) $query->count();
        $rows = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row->id,
                'total' => (float) $row->total,
                'userid' => (int) $row->userid,
                'user_name' => $row->user_name,
                'ref_by' => (int) $row->ref_by,
                'ref_user_name' => $row->ref_user_name,
                'ref_get' => (float) $row->ref_get,
                'invoice_id' => (int) $row->invoice_id,
                'datetime' => (int) $row->datetime,
                'datetime_text' => Tools::toDateTime((int) $row->datetime),
            ];
        }

        return self::ok($response, self::page($total, $page, $size, ['paybacks' => $out]));
    }

    /** GET /wumi/api/v1/billing/paylists */
    public function paylists(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        [$page, $size] = self::paging($request);

        $query = new Paylist();
        $total = (int) $query->count();
        $rows = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $names = [];
        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row->userid;
            if (! isset($names[$uid])) {
                $user = (new User())->find($uid);
                $names[$uid] = $user === null ? '' : $user->user_name;
            }
            $out[] = [
                'id' => (int) $row->id,
                'userid' => $uid,
                'user_name' => $names[$uid],
                'total' => (float) $row->total,
                'status' => (int) $row->status,
                'status_label' => $row->status(),
                'gateway' => $row->gateway,
                'tradeno' => $row->tradeno,
                'invoice_id' => (int) $row->invoice_id,
                'datetime' => (int) $row->datetime,
                'datetime_text' => Tools::toDateTime((int) $row->datetime),
            ];
        }

        return self::ok($response, self::page($total, $page, $size, ['paylists' => $out]));
    }

    /** GET /wumi/api/v1/billing/money-logs */
    public function moneyLogs(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        [$page, $size] = self::paging($request);

        $query = new UserMoneyLog();
        $total = (int) $query->count();
        $rows = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $size)
            ->take($size)
            ->get();

        $names = [];
        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            if (! isset($names[$uid])) {
                $user = (new User())->find($uid);
                $names[$uid] = $user === null ? '' : $user->user_name;
            }
            $out[] = [
                'id' => (int) $row->id,
                'user_id' => $uid,
                'user_name' => $names[$uid],
                'before' => (float) $row->before,
                'after' => (float) $row->after,
                'amount' => (float) $row->amount,
                'remark' => $row->remark,
                'create_time' => (int) $row->create_time,
                'create_time_text' => Tools::toDateTime((int) $row->create_time),
            ];
        }

        return self::ok($response, self::page($total, $page, $size, ['money_logs' => $out]));
    }

    // ------------------------------------------------------------------

    /** @return array{0:int,1:int} */
    private static function paging(ServerRequest $request): array
    {
        $page = max(1, (int) $request->getParam('page', 1));
        $size = max(1, (int) $request->getParam('size', 30));
        if ($size > self::MAX_PAGE_SIZE) {
            $size = self::MAX_PAGE_SIZE;
        }

        return [$page, $size];
    }

    /**
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function page(int $total, int $page, int $size, array $body): array
    {
        return [
            'total' => $total,
            'page' => $page,
            'size' => $size,
            'pages' => $total === 0 ? 0 : intdiv($total + $size - 1, $size),
        ] + $body;
    }

    private static function ok(Response $response, mixed $data): ResponseInterface
    {
        return $response->withJson(['ret' => 1, 'data' => $data]);
    }
}