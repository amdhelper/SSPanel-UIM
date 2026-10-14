#!/usr/bin/env bash
# SSPanel × wumi 内部 API 冒烟测试（P7 端到端实测）
#
#   bash docker/dev/smoke_wumi_api.sh
set -uo pipefail

BASE="${BASE:-http://localhost:18080}"
APIKEY="${APIKEY:-dev-wumi-api-key}"
USERKEY="${USERKEY:-dev-wumi-user-key}"
BODY=/tmp/wumi_smoke_body
pass=0
fail=0

req() { # METHOD PATH [extra curl args...]
  local method="$1"; shift
  local path="$1"; shift
  curl -sS -o "$BODY" -w '%{http_code}' -X "$method" "$BASE$path" \
    -H "X-Wumi-Api-Key: $APIKEY" -H "Accept: application/json" "$@"
}

# 断言 JSON：$1=描述  $2=python 表达式（d 为解析后的 JSON 对象）
pycheck() {
  if python3 -c "
import json, sys
d = json.load(open('$BODY'))
sys.exit(0 if ($2) else 1)
" >/dev/null 2>&1; then
    echo "  PASS  $1"; pass=$((pass + 1))
  else
    echo "  FAIL  $1  <- $(head -c 180 "$BODY")"; fail=$((fail + 1))
  fi
}

codecheck() { # 描述 期望码 实际码
  if [ "$3" = "$2" ]; then echo "  PASS  $1"; pass=$((pass + 1));
  else echo "  FAIL  $1  <- got $3"; fail=$((fail + 1)); fi
}

echo "== 负例：无/错机器密钥应 401 =="
codecheck "无密钥 → 401" 401 "$(curl -sS -o "$BODY" -w '%{http_code}' "$BASE/wumi/api/v1/me")"
codecheck "错密钥 → 401" 401 "$(curl -sS -o "$BODY" -w '%{http_code}' "$BASE/wumi/api/v1/me" -H 'X-Wumi-Api-Key: wrong')"

echo "== 身份桥 =="
codecheck "GET /me → 200" 200 "$(req GET /wumi/api/v1/me -H "X-Wumi-User-Key: $USERKEY")"
pycheck "/me ret=1 且回带 wumi 公钥" "d['ret']==1 and d['data']['wumi_user_key']=='$USERKEY'"

echo "== 节点桥 =="
codecheck "GET /nodes → 200" 200 "$(req GET /wumi/api/v1/nodes)"
pycheck "/nodes 含 VLESS 节点" "d['ret']==1 and any('VLESS' in (n.get('type_label') or '') for n in d['data'])"
codecheck "POST /nodes/sync → 200" 200 "$(req POST /wumi/api/v1/nodes/sync)"
pycheck "/nodes/sync ret=1" "d['ret']==1"

echo "== 商用接口 =="
codecheck "GET /plans → 200" 200 "$(req GET /wumi/api/v1/plans)"
pycheck "/plans 含在售套餐" "d['ret']==1 and len(d['data'])>=1 and d['data'][0]['price']==9.9"

codecheck "GET /subscription → 200" 200 "$(req GET /wumi/api/v1/subscription -H "X-Wumi-User-Key: $USERKEY")"
pycheck "/subscription 已购：含订阅链接+VLESS 串" "d['ret']==1 and d['data']['subscribed']==True and d['data']['sub_url'].startswith('http') and any((n.get('vless_uri') or '').startswith('vless://') for n in d['data']['nodes'])"
pycheck "/subscription 流量字段齐全" "all(k in d['data'] for k in ('traffic_used','traffic_total','class_expire','node_iplimit','node_speedlimit'))"

echo "== 订阅：未购买（class=0）只能看到节点名（2026-10-14 爸爸令）=="
NONBUYERKEY="${NONBUYERKEY:-dev-wumi-nonbuyer-key}"
codecheck "GET /subscription（未购）→ 200" 200 "$(req GET /wumi/api/v1/subscription -H "X-Wumi-User-Key: $NONBUYERKEY")"
pycheck "未购：节点仅剩 id/name/locked（无 server/vless_uri 等细节）" "d['ret']==1 and d['data']['subscribed']==False and len(d['data']['nodes'])>=1 and all(set(n.keys())=={'id','name','locked'} and n['locked']==True and bool(n['name']) for n in d['data']['nodes'])"
pycheck "未购：不下发订阅链接与 uuid" "d['data']['sub_url']=='' and d['data']['uuid']==''"

echo "== 订阅下载门禁（/sub）：未购不发放内容，已购恢复可用 =="
jpost() { # PATH JSON
  req POST "$1" -H 'Content-Type: application/json' -d "$2"
}
SUBTOKEN=$(python3 - <<PYEOF
import json, urllib.request
r = urllib.request.Request('$BASE/wumi/api/v1/subscription',
    headers={'X-Wumi-Api-Key': '$APIKEY', 'X-Wumi-User-Key': '$USERKEY'})
print(json.load(urllib.request.urlopen(r))['data']['sub_url'].rstrip('/').split('/')[-1])
PYEOF
)
UID_DEV=$(python3 - <<PYEOF
import json, urllib.request
r = urllib.request.Request('$BASE/wumi/api/v1/users?keyword=$USERKEY&size=5',
    headers={'X-Wumi-Api-Key': '$APIKEY'})
print(json.load(urllib.request.urlopen(r))['data']['users'][0]['id'])
PYEOF
)
codecheck "POST /users/$UID_DEV（临时降为 class=0）→ 200" 200 "$(jpost "/wumi/api/v1/users/$UID_DEV" '{"class":0}')"
codecheck "GET /sub/{token}/v2ray（未购）→ 200" 200 "$(curl -sS -o "$BODY" -w '%{http_code}' "$BASE/sub/$SUBTOKEN/v2ray")"
pycheck "未购 token 的订阅下载被拒（未开通）" "d['ret']==0 and '\u672a\u5f00\u901a' in d['msg']"
codecheck "POST /users/$UID_DEV（恢复已购 class=1/365天）→ 200" 200 "$(jpost "/wumi/api/v1/users/$UID_DEV" '{"class":1,"expire_days":365}')"
pycheck "恢复已购生效" "d['ret']==1 and d['data']['class']==1"
SUB_RESTORE=$(curl -sS "$BASE/sub/$SUBTOKEN/vless")
case "$SUB_RESTORE" in
  *vless://*) echo "  PASS  已购恢复后订阅可下载"; pass=$((pass + 1));;
  *) echo "  FAIL  已购恢复后订阅可下载  <- $(printf '%s' "$SUB_RESTORE" | head -c 120)"; fail=$((fail + 1));;
esac


codecheck "GET /orders → 200" 200 "$(req GET /wumi/api/v1/orders -H "X-Wumi-User-Key: $USERKEY")"
pycheck "/orders ret=1" "d['ret']==1"

PID=$(python3 -c "
import json, urllib.request
r = urllib.request.Request('$BASE/wumi/api/v1/plans', headers={'X-Wumi-Api-Key': '$APIKEY'})
print(json.load(urllib.request.urlopen(r))['data'][0]['id'])
" 2>/dev/null || echo 1)
codecheck "POST /orders → 200" 200 "$(req POST /wumi/api/v1/orders -H "X-Wumi-User-Key: $USERKEY" -H 'Content-Type: application/json' -d "{\"product_id\": $PID}")"
pycheck "/orders 返回 order_id" "d['ret']==1 and d['data']['order_id']>0"

codecheck "GET /billing/overview → 200" 200 "$(req GET /wumi/api/v1/billing/overview)"
pycheck "/billing/overview 含收入与订单字段" "d['ret']==1 and all(k in d['data'] for k in ('revenue_total','revenue_30d','order_total','paid_user_count','product_on_sale'))"

echo
echo "结果: pass=$pass fail=$fail"
[ "$fail" -eq 0 ] || exit 1
