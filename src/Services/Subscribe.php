<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Link;
use App\Models\Node;
use App\Services\Subscribe\Clash;
use App\Services\Subscribe\Json;
use App\Services\Subscribe\SingBox;
use App\Services\Subscribe\SIP002;
use App\Services\Subscribe\SIP008;
use App\Services\Subscribe\SS;
use App\Services\Subscribe\Trojan;
use App\Services\Subscribe\V2Ray;
use App\Services\Subscribe\V2RayJson;
use App\Services\Subscribe\VLESS;
use App\Utils\Tools;
use Illuminate\Support\Collection;
use function strtotime;
use function time;

final class Subscribe
{
    public static function getUniversalSubLink($user): string
    {
        $userid = $user->id;
        $token = (new Link())->where('userid', $userid)->first();

        if ($token === null) {
            $token = new Link();
            $token->userid = $userid;
            $token->token = Tools::genSubToken();
            $token->save();
        }

        return $_ENV['subUrl'] . '/sub/' . $token->token;
    }

    /**
     * 是否持有有效的梯子套餐（class>0 且未过期）。
     *
     * 🔥 2026-10-14 爸爸令：未购买梯子套餐（class=0 或已过期）的用户「只能看到节点名」，
     * 看不到节点细节，也不发放订阅链接/uuid —— 该判定单点收口在此：
     * APP 订阅接口（Wumi\CommerceController::subscription）与订阅下载端点
     * （SubController）共用，改口径只改这里。
     */
    public static function isSubscribed($user): bool
    {
        return (int) $user->class > 0 && strtotime((string) $user->class_expire) >= time();
    }

    public static function getUserNodes($user, bool $show_all_nodes = false): Collection
    {
        $query = Node::query();
        $query->where('type', 1);

        if (! $show_all_nodes) {
            $query->where('node_class', '<=', $user->class);
        }

        if (! $user->is_admin) {
            $group = ($user->node_group !== 0 ? [0, $user->node_group] : [0]);
            $query->whereIn('node_group', $group);
        }

        return $query->where(static function ($query): void {
            $query->where('node_bandwidth_limit', '=', 0)->orWhereRaw('node_bandwidth < node_bandwidth_limit');
        })->orderBy('node_class')
            ->orderBy('name')
            ->get();
    }

    public static function getContent($user, string $type): string
    {
        return self::getClient($type)->getContent($user);
    }

    public static function getClient(string $type): Json|SS|SIP002|V2Ray|VLESS|Trojan|Clash|SIP008|SingBox|V2RayJson
    {
        return match ($type) {
            'ss' => new SS(),
            'sip002' => new SIP002(),
            'v2ray' => new V2Ray(),
            'vless' => new VLESS(),
            'trojan' => new Trojan(),
            'clash' => new Clash(),
            'sip008' => new SIP008(),
            'singbox' => new SingBox(),
            'v2rayjson' => new V2RayJson(),
            default => new Json(),
        };
    }
}
