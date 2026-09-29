# Wumi × SSPanel：Xray 信令节点 / 梯子 集成设计

> 状态：进行中（分支 `feat/wumi-xray-integration`）
> 决策日期：2026-09-30（爸爸确认）
> 上游：`amdhelper/SSPanel-UIM`（fork of `Anankke/SSPanel-UIM`）

## 1. 目标

1. 让面板**完美支持**由 mono_zen `scripts/deploy.sh signaling` 部署出来的 Xray 节点。
2. 把 SSPanel 的**管理端能力**吸收进 wumi「站点管理（站务管理）」——新增
   **信令节点管理** Tab（节点状况）与 **收费管理** Tab（统一订阅/付费）。
3. 把 SSPanel 的**用户端**改造成 wumi **发现页**里的「**梯子**」页面（选购 xray 服务）。
4. **用户体系沿用 wumi**：wumi 用户 = 订阅者；SSPanel 只是 wumi 的一个扩展功能。

## 2. 现状

### 2.1 deploy.sh 的 Xray 节点形态（`signaling_configure_xray`）

- Xray 监听 `443`，`vless`，流控 `xtls-rprx-vision`，TLS（`/ssl/xray.crt|key`）。
- 同一 inbound 挂 4 条 fallback：

  | path | dest | xver | 用途 |
  |---|---|---|---|
  | 默认 | `8080` | 1 | Nginx 伪装站 |
  | `<ws_path>`（随机 8 hex） | `8081` | 1 | 梯子客户端入口（VLESS/WS） |
  | `/signaling/` | `127.0.0.1:8189` | 0 | wumi 信令 / Janus |
  | `/turn/` | `127.0.0.1:3478` | 0 | TURN over fallback |

- 配置信息落 `/usr/local/etc/xray/signaling_info.json`。
- 注册到主站：`POST {API_URL}/nodes/register`
  （字段：name/domain/port/node_type/turn_*/janus_ws_port/public_ip/secret_key/turn_auth_mode）。
- 非交互式部署入口：`./scripts/deploy.sh signaling auto-install <domain> <api_url> <secret_key> <node_name> [--skip-web] [--cdn]`。
- 另有 `install` / `install-full` / `register` / `heartbeat` / `status` / `check` 等子命令。

### 2.2 SSPanel-UIM 现状

- Laravel + Slim（Models 用 illuminate/database，Controllers 用 Slim）。
- 节点接入类型 `Node.sort`：`0` SS / `1` SS2022 / `2` TUIC / `3` WireGuard / `11` Vmess / `14` Trojan
  —— **无 VLESS**。
- 节点管理：`src/Controllers/Admin/NodeController.php` + Smarty 模板 `resources/views/tabler/admin/node/*.tpl`。
- 节点给后端（XrayR）的 API：`/mod_mu/nodes/{id}/info` 等（`App\Controllers\WebAPI`）。
- 订阅：`App\Services\Subscribe` + `src/Services/Subscribe/{SS,SIP002,V2Ray,Trojan,Clash,...}.php`。
- 商用体系：套餐/订单/支付/工单/邀请返利（`Product`/`Order`/`Gateway`/…）。

### 2.3 wumi 侧

- 运行态节点表：`signaling_nodes`（`apps/wumi/go_backend/internal/models/node.go`），
  `GET /nodes` 下发 TURN 凭据；节点心跳上报。
- 站务管理：`apps/wumi/frontend/lib/screens/station_admin_screen.dart`（现有 6 Tab：
  晋升配置 / 服务器监控 / 审核 / 运维 / 题库 / 机器人）。
- 发现页：`apps/wumi/frontend/lib/discover/discover_screen.dart`。

## 3. 已确认架构决策

| # | 决策 | 结论 |
|---|---|---|
| D1 | 「梯子」定位 | **商用订阅全套**；用户体系沿用 wumi（把 wumi 用户体系放进 SSPanel 商用体系） |
| D2 | 信令节点管理 | **复刻 SSPanel 节点管理**（等级/倍率/限速/流量/设备数限制），桥接 wumi `signaling_nodes` |
| D3 | 部署关系 | SSPanel = **wumi 的扩展功能**；wumi 为唯一用户体系与入口 |
| D4 | 收费管理 | 新增统一「收费管理」：涵盖 xray 订阅 + zen 等程序的付费订阅状况 |

### 3.1 集成方式（拟定）

- **身份**：wumi 用户以 `public_key` 作为 SSPanel 用户主键（或映射表 `wumi_user_id ↔ sspanel_user_id`），
  登录态由 wumi 颁发，SSPanel 侧只信任 wumi 身份（签名/JWT），不再维护独立账号密码体系。
- **节点**：SSPanel `node` 表为**配置态**（等级/倍率/限速/流量上限/设备数），
  运行态（在线/心跳/TURN 凭据）读 wumi `signaling_nodes`；两边以 `domain`/`public_ip` 关联。
- **订阅**：用户订阅串直接由 SSPanel 生成（新增 `vless` 订阅类型），指向 deploy.sh 节点的 `<ws_path>`。
- **provisioning**：面板按节点参数生成 deploy.sh `auto-install` 命令与 Xray `config.json`。

## 4. 分阶段计划

- **P1 ✅（已合并）** SSPanel 支持 deploy.sh Xray(VLESS+WS/TLS) 节点
  - `App\Services\Xray`（配置生成器/订阅串/部署命令/注册体）；`Node.sort = 20`；
    `vless` 订阅类型；后台「生成配置」页 `GET /admin/node/{id}/xray`；回归测试。
- **P2 ✅ 已做** wumi 身份桥（SSPanel 侧，wumi 无需改动）
  - 迁移 `2026093000-add_wumi_identity`：`user.wumi_user_key`（唯一） + `user.wumi_synced_at`。
  - `App\Services\Wumi\Jwt`：零依赖 HS256（与 golang-jwt/v5 互通，已跨实现验证）。
  - `App\Services\Wumi\Identity`：`publicKeyFromToken()` / `resolveUser()`（首访自动开户）/ `serviceToken()`。
  - `App\Middleware\WumiApi`：`X-Wumi-Api-Key` + `X-Wumi-User-Key`。
  - 路由：`GET /wumi/sso`（wumi→SSPanel SSO）、`GET /wumi/api/v1/me`。
  - 配置项：`wumi_api_url` / `wumi_jwt_secret` / `wumi_api_key` / `wumi_service_key` / `wumi_sso_enabled`。
- **P3 ✅ 已做** 节点桥（配置态↔运行态，SSPanel 侧）
  - `App\Services\Wumi\NodeBridge`：服务账号 token 调 wumi `GET /nodes`，按 domain/public_ip
    归一匹配，回灌 `node_heartbeat`/`online_user`/`ipv4`/`custom_config.wumi_*`。
  - 路由：`GET /wumi/api/v1/nodes`（配置态清单）、`POST /wumi/api/v1/nodes/sync`。
  - 命令：`php xcat WumiSyncNodes`。
- **P4 ✅ 已做** wumi 站务管理 Tab「信令节点管理」
  - wumi 后端：`App\...\LadderService`（机器密钥代理 SSPanel）、`LadderAdminHandler`
    （`GET /admin/ladder/nodes` 配置态+运行态合并、`POST /admin/ladder/nodes/sync`）。
  - wumi 前端：station_admin_screen 第 7 Tab；l10n 12 键；契约 registry 1.34.0 / SPEC 0.3.22。
- **P5 ✅ 已做** wumi 站务管理 Tab「收费管理」
  - SSPanel：`GET /wumi/api/v1/admin/overview`（付费用户/绑定用户/订单/收入/在售商品）。
  - wumi 后端：`GET /admin/ladder/overview`；前端 station_admin 第 8 Tab；l10n 7 键。
- **P6 ✅ 已做** 发现页「梯子」
  - SSPanel：`GET /wumi/api/v1/plans|subscription|orders` + `POST /wumi/api/v1/orders`
    （订阅含流量/到期/订阅链接/可用节点 + `vless://` 串；下单复用网站规则生成 Order+Invoice）。
  - wumi 后端：`GET /ladder/plans|subscription|orders`、`POST /ladder/orders`。
  - wumi 前端：发现页「实用工具」新增「梯子」入口 → `LadderScreen`（套餐/我的订阅/我的订单）。
  - 契约 registry 1.35.0 / SPEC 0.3.23。
- **P7 ✅ 已做（本地端到端）** 
  - 本地开发栈：`docker/dev/`（php 8.3-CLI + MariaDB + Redis）→ `bootstrap_dev.sh` 建库/导入设置/造数据
    → `smoke_wumi_api.sh` 冒烟（**19/19 通过**）。
  - 跨仓库契约：wumi `internal/service/ladder_live_test.go` 直连本机 SSPanel 实例
    （plans/nodes/subscription/vless 串/下单/订单/收费总览/节点同步 全通过）。
  - ⏳ 未做：部署到线上 + 爸爸真机验收（会改线上系统，需明确授权）。

## 6. 实测踩到的坑（务必遵守）

- 🔴 **SSPanel 全局 `ErrorHandler` 会把任何含 `/admin` 的 URL 重定向到 `/auth/login`**
  （非管理员，实测 302）——内部 API 路径**不得包含 `/admin`**，
  故收费总览用 `/wumi/api/v1/billing/overview`（原 `/admin/overview` 被拦）。
- 🔴 `php xcat Migration new` **只跑「最早一个」迁移**（并把 db_version 设为它），
  之后必须再跑 `php xcat Migration latest` 才能把全部迁移（含新增的）应用上。
- 🔴 本地 php 镜像缺 `ext-gmp` 时 composer 装不上（`starkbank/ecdsa` 依赖 alipaysdk 链）。

## 5. 本分支已完成内容（可核验）

```
# P1
src/Services/Xray.php                     # 配置生成器（新）
src/Services/Subscribe/VLESS.php          # VLESS 订阅（新）
src/Services/Subscribe.php                # 注册 vless 类型
src/Models/Node.php                       # sort=20 => VLESS (Xray)
src/Controllers/SubController.php         # 订阅类型白名单加 vless
src/Controllers/Admin/NodeController.php  # 生成配置页 + 列表按钮
resources/views/tabler/admin/node/create.tpl  # 接入类型下拉加 VLESS
resources/views/tabler/admin/node/edit.tpl    # 同上
resources/views/tabler/admin/node/xray.tpl    # 生成配置页（新）
# P2
db/migrations/2026093000-add_wumi_identity.php  # user.wumi_user_key/synced_at（新）
src/Services/Wumi/Jwt.php                 # 零依赖 HS256（新）
src/Services/Wumi/Identity.php            # 身份桥（新）
src/Middleware/WumiApi.php                # 机器密钥中间件（新）
src/Controllers/Wumi/IdentityController.php   # sso / me（新）
# P3
src/Services/Wumi/NodeBridge.php          # 节点桥（新）
src/Controllers/Wumi/NodeController.php   # nodes / nodes.sync（新）
src/Command/WumiSyncNodes.php             # php xcat WumiSyncNodes（新）
app/routes.php                            # /wumi 路由组
config/settings.json                      # wumi_* 配置项
tests/Integration/Services/XrayTest.php        # 回归测试（新）
tests/Integration/Services/WumiIdentityTest.php # 回归测试（新）
```

验证方式：`php -l` 全绿；独立断言脚本 30+（Xray）+ 13（身份桥）全通过；
跨实现互操作：PHP 签发 HS256 token → Go 用 wumi 同款 `golang-jwt/jwt/v5` 解析成功、错密钥被拒。