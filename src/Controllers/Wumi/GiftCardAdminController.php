<?php

declare(strict_types=1);

namespace App\Controllers\Wumi;

use App\Controllers\BaseController;
use App\Models\GiftCard;
use App\Utils\Tools;
use Psr\Http\Message\ResponseInterface;
use Slim\Http\Response;
use Slim\Http\ServerRequest;
use function in_array;
use function time;
use function trim;

/**
 * wumi「收费管理 → 礼品卡」读写。
 *
 * 复刻 SSPanel 后台礼品卡能力：批量生成（指定面值 + 卡号长度）、列表、删除。
 * 卡号可给用户兑换余额（兑换入口在 SSPanel 用户侧，与本站一致）。
 *
 *   GET    /wumi/api/v1/gift-cards        礼品卡列表
 *   POST   /wumi/api/v1/gift-cards        批量生成
 *   DELETE /wumi/api/v1/gift-cards/{id}   删除
 *
 * ⚠️ 路径**不得包含 `/admin`**。
 */
final class GiftCardAdminController extends BaseController
{
    /** 允许的卡号长度（与 SSPanel 后台下拉一致） */
    public const LENGTHS = [12, 18, 24, 30, 36];

    /** GET /wumi/api/v1/gift-cards */
    public function index(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $out = [];
        foreach ((new GiftCard())->orderBy('id', 'desc')->limit(200)->get() as $card) {
            $out[] = [
                'id' => (int) $card->id,
                'card' => $card->card,
                'balance' => (int) $card->balance,
                'status' => (int) $card->status,
                'status_label' => $card->status(),
                'create_time' => (int) $card->create_time,
                'use_time' => (int) $card->use_time,
                'use_user' => (int) $card->use_user,
            ];
        }

        return self::ok($response, $out);
    }

    /** POST /wumi/api/v1/gift-cards  body: {card_number, card_value, card_length} */
    public function create(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $count = (int) $request->getParam('card_number', 0);
        $value = (int) $request->getParam('card_value', 0);
        $length = (int) $request->getParam('card_length', 0);

        if ($count <= 0 || $count > 500) {
            return self::fail($response, '生成数量需在 1~500 之间');
        }
        if ($value <= 0) {
            return self::fail($response, '礼品卡面值必须大于 0');
        }
        if (! in_array($length, self::LENGTHS, true)) {
            return self::fail($response, '卡号长度非法（12/18/24/30/36）');
        }

        $cards = [];
        for ($i = 0; $i < $count; $i++) {
            $card = (string) Tools::genRandomChar($length);

            $giftCard = new GiftCard();
            $giftCard->card = $card;
            $giftCard->balance = $value;
            $giftCard->create_time = time();
            $giftCard->status = 0;
            $giftCard->use_time = 0;
            $giftCard->use_user = 0;
            $giftCard->save();

            $cards[] = $card;
        }

        return self::ok($response, ['generated' => $count, 'cards' => $cards]);
    }

    /** DELETE /wumi/api/v1/gift-cards/{id} */
    public function delete(ServerRequest $request, Response $response, array $args): ResponseInterface
    {
        $card = (new GiftCard())->find($args['id']);
        if ($card === null) {
            return self::fail($response, '礼品卡不存在');
        }
        $card->delete();

        return self::ok($response, ['deleted' => true, 'gift_card_id' => (int) $args['id']]);
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