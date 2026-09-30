#!/usr/bin/env bash
# SSPanel × wumi 管理写接口冒烟测试（节点/商品/用户/账单）
#
#   bash docker/dev/smoke_wumi_admin.sh
#
# 需要先跑过 docker/dev/bootstrap_dev.sh 与 smoke_wumi_api.sh。
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# 复用既有冒烟脚本里的 dev 机器密钥/用户密钥默认值（避免在仓库里再抄一份）
eval "$(grep -m1 '^APIKEY=' "$HERE/smoke_wumi_api.sh")"
eval "$(grep -m1 '^USERKEY=' "$HERE/smoke_wumi_api.sh")"

BASE="${BASE:-http://localhost:18080}"
BODY=/tmp/wumi_admin_smoke_body
pass=0
fail=0

req() { # METHOD PATH [extra curl args...]
  local method="$1"; shift
  local path="$1"; shift
  curl -sS -o "$BODY" -w '%{http_code}' -X "$method" "$BASE$path" \
    -H "X-Wumi-Api-Key: $APIKEY" -H 'Accept: application/json' "$@"
}

jpost() { # PATH JSON
  req POST "$1" -H 'Content-Type: application/json' -d "$2"
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
    echo "  FAIL  $1  <- $(head -c 200 "$BODY")"; fail=$((fail + 1))
  fi
}

codecheck() {
  if [ "$3" = "$2" ]; then echo "  PASS  $1"; pass=$((pass + 1));
  else echo "  FAIL  $1  <- got $3: $(head -c 200 "$BODY")"; fail=$((fail + 1)); fi
}

echo "== 节点管理：用链接导入 =="
VLINK='vless://11111111-2222-3333-4444-555555555555@node-import.test:443?encryption=none&security=tls&type=ws&host=node-import.test&path=%2Fabc123%2F&sni=node-import.test#ImportedNode'
codecheck "POST /nodes/import → 200" 200 "$(jpost /wumi/api/v1/nodes/import "{\"input\":\"$VLINK\",\"node_class\":2,\"traffic_rate\":1.5}")"
pycheck "导入 1 个节点且等级/倍率生效" "d['ret']==1 and d['data']['parsed']==1 and len(d['data']['nodes'])==1 and d['data']['nodes'][0]['node_class']==2 and d['data']['nodes'][0]['traffic_rate']==1.5"
IMPORTED_ID=$(python3 -c "import json;print(json.load(open('$BODY'))['data']['nodes'][0]['id'])" 2>/dev/null || echo 0)
pycheck "导入节点为 VLESS 且带 ws_path" "d['data']['nodes'][0]['sort']==20 and '/abc123/' in json.dumps(d['data']['nodes'][0]['custom_config'])"
codecheck "DELETE 导入节点（清理）→ 200" 200 "$(req DELETE "/wumi/api/v1/nodes/$IMPORTED_ID")"

echo "== 节点导入：同地址不同端口 = 不同节点 =="
ML1='vless://aaaa1111-2222-3333-4444-555555555555@multi-port.test:443?encryption=none&security=tls&type=ws&path=%2Fa%2F&host=multi-port.test#MP-443'
ML2='vless://bbbb1111-2222-3333-4444-555555555555@multi-port.test:8443?encryption=none&security=tls&type=ws&path=%2Fb%2F&host=multi-port.test#MP-8443'
codecheck "导入 443 端口 → 200" 200 "$(jpost /wumi/api/v1/nodes/import "{\"input\":\"$ML1\"}")"
pycheck "443 解析成功" "d['ret']==1 and d['data']['parsed']==1"
codecheck "导入 8443 端口 → 200" 200 "$(jpost /wumi/api/v1/nodes/import "{\"input\":\"$ML2\"}")"
pycheck "8443 解析成功" "d['ret']==1 and d['data']['parsed']==1"
MP_IDS=$(python3 -c "
import json, urllib.request
r = urllib.request.Request('$BASE/wumi/api/v1/nodes', headers={'X-Wumi-Api-Key': '$APIKEY'})
d = json.load(urllib.request.urlopen(r))
print(' '.join(str(n['id']) for n in d['data'] if n.get('server') == 'multi-port.test'))" 2>/dev/null || echo "")
MP_COUNT=$(echo $MP_IDS | wc -w)
codecheck "同地址不同端口 → 2 个独立节点" 2 "$MP_COUNT"
for mid in $MP_IDS; do
  codecheck "DELETE /nodes/$mid（清理）→ 200" 200 "$(req DELETE "/wumi/api/v1/nodes/$mid")"
done

echo "== 节点导入：多行粘贴（一行一条，UI 批量粘贴的真实形态） =="
# 🔴 回归防护：整段文本以 vless:// 开头，若 parseInput 不先判「单行」，
# 会把多条链接当成 1 条解析 → parsed=0。
MB1='vless://e0111111-2222-3333-4444-555555555555@batch-one.test:443?encryption=none&security=tls&type=ws&path=%2Fa%2F&host=batch-one.test#BATCH-1'
MB2='vless://e0222222-2222-3333-4444-555555555555@batch-two.test:443?encryption=none&security=tls&type=ws&path=%2Fb%2F&host=batch-two.test#BATCH-2'
codecheck "多行粘贴批量导入 → 200" 200 "$(WUMI_BASE="$BASE" WUMI_KEY="$APIKEY" MB1="$MB1" MB2="$MB2" python3 -c '
import json, os, urllib.request
payload = json.dumps({"input": os.environ["MB1"] + "\n" + os.environ["MB2"]}).encode()
req = urllib.request.Request(os.environ["WUMI_BASE"] + "/wumi/api/v1/nodes/import", data=payload,
    headers={"X-Wumi-Api-Key": os.environ["WUMI_KEY"], "Content-Type": "application/json"})
try:
    body = urllib.request.urlopen(req).read().decode()
except Exception:
    body = ""
open("/tmp/wumi_admin_smoke_body", "w").write(body)
print(200 if body else 0)
')"
pycheck "多行粘贴解析出 2 个节点" "d['ret']==1 and d['data']['parsed']==2"
BATCH_IDS=$(python3 -c "
import json, urllib.request
r = urllib.request.Request('$BASE/wumi/api/v1/nodes', headers={'X-Wumi-Api-Key': '$APIKEY'})
d = json.load(urllib.request.urlopen(r))
print(' '.join(str(n['id']) for n in d['data'] if str(n.get('server', '')).startswith('batch-')))" 2>/dev/null || echo "")
codecheck "多行粘贴 → 2 个独立节点" 2 "$(echo $BATCH_IDS | wc -w)"
for bid in $BATCH_IDS; do
  codecheck "DELETE /nodes/$bid（清理）→ 200" 200 "$(req DELETE "/wumi/api/v1/nodes/$bid")"
done

echo "== 节点管理：增删改 / 启停 / 重置流量 =="
codecheck "POST /nodes（新建）→ 200" 200 "$(jpost /wumi/api/v1/nodes '{"name":"smoke-node","server":"smoke-node.test","sort":20,"node_class":1,"traffic_rate":1,"node_speedlimit":100,"node_bandwidth_limit":500,"type":true,"custom_config":{"port":443,"ws_path":"/smoke/"}}')"
pycheck "新建返回 node_id" "d['ret']==1 and d['data']['node_id']>0"
NID=$(python3 -c "import json;print(json.load(open('$BODY'))['data']['node_id'])" 2>/dev/null || echo 0)

codecheck "GET /nodes/{id} → 200" 200 "$(req GET "/wumi/api/v1/nodes/$NID")"
pycheck "详情回带限速/流量/自定义配置" "d['ret']==1 and d['data']['node_speedlimit']==100 and d['data']['custom_config']['ws_path']=='/smoke/'"

codecheck "POST /nodes/{id}（改等级/倍率）→ 200" 200 "$(jpost "/wumi/api/v1/nodes/$NID" '{"node_class":3,"traffic_rate":2.5,"node_group":1}')"
pycheck "修改生效" "d['ret']==1 and d['data']['node']['node_class']==3 and d['data']['node']['traffic_rate']==2.5"

codecheck "POST /nodes/{id}/toggle → 200" 200 "$(req POST "/wumi/api/v1/nodes/$NID/toggle")"
pycheck "启停翻转（type=0）" "d['ret']==1 and d['data']['type']==0"
codecheck "POST /nodes/{id}/reset-bandwidth → 200" 200 "$(req POST "/wumi/api/v1/nodes/$NID/reset-bandwidth")"
pycheck "重置流量 node_bandwidth=0" "d['ret']==1 and d['data']['node_bandwidth']==0"

codecheck "DELETE /nodes/{id} → 200" 200 "$(req DELETE "/wumi/api/v1/nodes/$NID")"
pycheck "删除生效" "d['ret']==1 and d['data']['deleted']==True"

echo "== 商品/价格管理 =="
codecheck "GET /products → 200" 200 "$(req GET /wumi/api/v1/products)"
pycheck "商品列表含类型/价格/限购" "d['ret']==1 and len(d['data'])>=1 and all(k in d['data'][0] for k in ('price','type_label','limit','content'))"

codecheck "POST /products（新建 tabp）→ 200" 200 "$(jpost /wumi/api/v1/products '{"type":"tabp","name":"smoke-product","price":19.9,"status":1,"stock":-1,"time":30,"bandwidth":100,"class":1,"class_time":30,"node_group":0,"speed_limit":100,"ip_limit":3}')"
pycheck "新建商品返回 product_id" "d['ret']==1 and d['data']['product_id']>0"
PID=$(python3 -c "import json;print(json.load(open('$BODY'))['data']['product_id'])" 2>/dev/null || echo 0)

codecheck "POST /products/{id}（改价）→ 200" 200 "$(jpost "/wumi/api/v1/products/$PID" '{"price":29.9,"status":0}')"
pycheck "改价生效" "d['ret']==1 and d['data']['product']['price']==29.9 and d['data']['product']['status']==0"

codecheck "POST /products/{id}（非法类型）→ ret=0" 200 "$(jpost "/wumi/api/v1/products/$PID" '{"type":"bogus"}')"
pycheck "非法类型被拒" "d['ret']==0"

codecheck "DELETE /products/{id} → 200" 200 "$(req DELETE "/wumi/api/v1/products/$PID")"
pycheck "删除商品生效" "d['ret']==1 and d['data']['deleted']==True"

echo "== 用户管理（SSPanel 属性）=="
codecheck "GET /users → 200" 200 "$(req GET '/wumi/api/v1/users?size=5')"
pycheck "用户列表分页字段齐全" "d['ret']==1 and all(k in d['data'] for k in ('total','users','pages')) and len(d['data']['users'])>=1"
U_ID=$(python3 -c "import json;print(json.load(open('$BODY'))['data']['users'][0]['id'])" 2>/dev/null || echo 0)

codecheck "GET /users/{id} → 200" 200 "$(req GET "/wumi/api/v1/users/$U_ID")"
pycheck "用户详情含流量/限速/到期" "d['ret']==1 and all(k in d['data'] for k in ('transfer_enable','node_speedlimit','node_iplimit','class_expire','wumi_bound'))"

codecheck "POST /users/{id}（改 SSPanel 属性）→ 200" 200 "$(jpost "/wumi/api/v1/users/$U_ID" '{"class":3,"transfer_enable":200,"node_speedlimit":300,"node_iplimit":5,"node_group":2}')"
pycheck "属性修改生效" "d['ret']==1 and d['data']['class']==3 and d['data']['node_speedlimit']==300 and d['data']['node_iplimit']==5 and d['data']['transfer_enable_gb']==200"

codecheck "POST /users/{id}/reset-traffic → 200" 200 "$(req POST "/wumi/api/v1/users/$U_ID/reset-traffic")"
pycheck "流量归零" "d['ret']==1 and d['data']['traffic_used']==0"

echo "== 收费管理（订单/账单）=="
codecheck "GET /billing/orders → 200" 200 "$(req GET '/wumi/api/v1/billing/orders?size=5')"
pycheck "订单列表含状态/用户" "d['ret']==1 and all(k in d['data'] for k in ('orders','total'))"
OID=$(python3 -c "
import json
d=json.load(open('$BODY'))
orders=[o for o in d['data']['orders'] if o['status'] not in ('cancelled',)]
print(orders[0]['id'] if orders else 0)
" 2>/dev/null || echo 0)

codecheck "POST /billing/orders/{id}/activate → 200" 200 "$(req POST "/wumi/api/v1/billing/orders/$OID/activate")"
pycheck "订单转待激活" "d['ret']==1 and d['data']['status']=='pending_activation'"

codecheck "GET /billing/invoices → 200" 200 "$(req GET '/wumi/api/v1/billing/invoices?size=5')"
pycheck "账单列表含金额/状态" "d['ret']==1 and all(k in d['data'] for k in ('invoices','total'))"
IID=$(python3 -c "
import json
d=json.load(open('$BODY'))
invs=[i for i in d['data']['invoices'] if i['status'] not in ('paid_admin','paid_gateway','paid_balance')]
print(invs[0]['id'] if invs else 0)
" 2>/dev/null || echo 0)

if [ "$IID" != "0" ]; then
  codecheck "POST /billing/invoices/{id}/mark-paid → 200" 200 "$(req POST "/wumi/api/v1/billing/invoices/$IID/mark-paid")"
  pycheck "账单标记已支付" "d['ret']==1 and d['data']['status']=='paid_admin'"
fi

echo "== 收费管理：优惠码 =="
CODE="SMOKE$RANDOM"
codecheck "GET /coupons → 200" 200 "$(req GET /wumi/api/v1/coupons)"
pycheck "优惠码列表为数组" "d['ret']==1 and isinstance(d['data'], list)"

codecheck "POST /coupons（新建）→ 200" 200 "$(jpost /wumi/api/v1/coupons "{\"code\":\"$CODE\",\"type\":\"percentage\",\"value\":10,\"use_time\":1,\"total_use_time\":-1,\"new_user\":0,\"generate_method\":\"char\"}")"
pycheck "新建优惠码返回实体" "d['ret']==1 and d['data']['coupon']['code']=='$CODE'"
CID=$(python3 -c "import json;print(json.load(open('$BODY'))['data']['coupon']['id'])" 2>/dev/null || echo 0)

codecheck "POST /coupons/{id}（改额度）→ 200" 200 "$(jpost "/wumi/api/v1/coupons/$CID" '{"value":20}')"
pycheck "改额度生效" "d['ret']==1 and d['data']['coupon']['value']==20"
codecheck "POST /coupons/{id}/disable → 200" 200 "$(req POST "/wumi/api/v1/coupons/$CID/disable")"
pycheck "禁用生效" "d['ret']==1 and d['data']['coupon']['disabled']==1"
codecheck "DELETE /coupons/{id} → 200" 200 "$(req DELETE "/wumi/api/v1/coupons/$CID")"
pycheck "删除优惠码" "d['ret']==1 and d['data']['deleted']==True"

echo "== 收费管理：礼品卡 =="
codecheck "GET /gift-cards → 200" 200 "$(req GET /wumi/api/v1/gift-cards)"
codecheck "POST /gift-cards（生成 2 张 12 位）→ 200" 200 "$(jpost /wumi/api/v1/gift-cards '{"card_number":2,"card_value":10,"card_length":12}')"
pycheck "生成 2 张礼品卡" "d['ret']==1 and d['data']['generated']==2 and len(d['data']['cards'])==2"
GIDS=$(python3 -c "
import json, urllib.request
r = urllib.request.Request('$BASE/wumi/api/v1/gift-cards', headers={'X-Wumi-Api-Key': '$APIKEY'})
d = json.load(urllib.request.urlopen(r))
print(' '.join(str(c['id']) for c in d['data'][:2]))" 2>/dev/null || echo "")
for gid in $GIDS; do
  codecheck "DELETE /gift-cards/$gid（清理）→ 200" 200 "$(req DELETE "/wumi/api/v1/gift-cards/$gid")"
  pycheck "删除礼品卡" "d['ret']==1 and d['data']['deleted']==True"
done

echo "== 收费管理：流水（返利 / 网关 / 余额）=="
codecheck "GET /billing/paylists → 200" 200 "$(req GET '/wumi/api/v1/billing/paylists?size=5')"
pycheck "交易流水分页字段" "d['ret']==1 and all(k in d['data'] for k in ('total','paylists','pages'))"
codecheck "GET /billing/money-logs → 200" 200 "$(req GET '/wumi/api/v1/billing/money-logs?size=5')"
pycheck "余额流水分页字段" "d['ret']==1 and all(k in d['data'] for k in ('total','money_logs','pages'))"
codecheck "GET /billing/paybacks → 200" 200 "$(req GET '/wumi/api/v1/billing/paybacks?size=5')"
pycheck "返利记录分页字段" "d['ret']==1 and all(k in d['data'] for k in ('total','paybacks','pages'))"

echo "== 收费管理：支付网关 =="
codecheck "GET /billing/gateways → 200" 200 "$(req GET /wumi/api/v1/billing/gateways)"
pycheck "网关清单 + 密钥不回传明文" "d['ret']==1 and len(d['data']['gateways'])>=1 and all(k in d['data']['gateways'][0] for k in ('slug','label','active')) and any(s['secret'] for s in d['data']['settings']) and all(s['value']=='' for s in d['data']['settings'] if s['secret'])"
codecheck "POST /billing/gateways（写非密钥项）→ 200" 200 "$(jpost /wumi/api/v1/billing/gateways '{"settings":{"stripe_currency":"USD"}}')"
pycheck "写配置成功" "d['ret']==1 and len(d['data']['gateways'])>=1"
codecheck "POST /billing/gateways（非法配置项）→ ret=0" 200 "$(jpost /wumi/api/v1/billing/gateways '{"settings":{"totally_bogus_item":"1"}}')"
pycheck "非法配置项被拒" "d['ret']==0"

echo "== 节点：生成部署配置 / 部署命令 =="
NODE_L=$(python3 -c "
import json, urllib.request
r = urllib.request.Request('$BASE/wumi/api/v1/nodes', headers={'X-Wumi-Api-Key': '$APIKEY'})
d = json.load(urllib.request.urlopen(r))
ids = [n['id'] for n in d['data'] if n.get('sort') == 20]
print(ids[0] if ids else 0)" 2>/dev/null || echo 0)
codecheck "GET /nodes/{id}/deploy → 200" 200 "$(req GET "/wumi/api/v1/nodes/$NODE_L/deploy?api_url=http://panel.local&secret_key=sk-test")"
pycheck "含 config_json / 部署命令 / 订阅示例" "d['ret']==1 and 'fallbacks' in d['data']['config_json'] and 'auto-install' in d['data']['deploy_command'] and d['data']['vless_uri_example'].startswith('vless://')"

echo
echo "结果: pass=$pass fail=$fail"
[ "$fail" -eq 0 ] || exit 1