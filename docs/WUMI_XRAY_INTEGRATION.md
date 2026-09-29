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

- **P1（本分支已做）** SSPanel 支持 deploy.sh Xray(VLESS+WS/TLS) 节点
  - [x] `App\Services\Xray`：`generateServerConfig()` 逐字段对齐 deploy.sh；`buildVlessUri()`；
        `buildDeployCommand()`；`buildRegisterPayload()`。
  - [x] `Node.sort = 20` → `VLESS (Xray)`；后台建/改节点下拉新增该类型。
  - [x] 新增 `vless` 订阅类型（`App\Services\Subscribe\VLESS`）。
  - [x] 回归测试 `tests/Integration/Services/XrayTest.php`（无 DB 依赖）。
  - [ ] 后台节点页「生成 Xray 配置 / 部署命令」按钮与预览页。
  - [ ] `/mod_mu/nodes/{id}/info` 对 sort=20 下发 VLESS 节点信息。
- **P2** wumi 用户体系接入 SSPanel（身份映射 + 免密登录/信任链）。
- **P3** 节点管理桥接：SSPanel 节点 ↔ wumi `signaling_nodes`（配置态/运行态同步 + 心跳）。
- **P4** wumi 站务管理新增 Tab：**信令节点管理**（节点状况 + 等级/倍率/限速/流量/设备数）。
- **P5** wumi 站务管理新增 Tab：**收费管理**（xray + zen 等统一订阅/付费状况）。
- **P6** wumi 发现页新增「**梯子**」：套餐选购 → 下单支付 → 我的订阅 → 节点/流量/订阅链接/二维码。
- **P7** 端到端验证、部署、知识库同步。

## 5. 本分支已完成内容（可核验）

```
src/Services/Xray.php                     # 配置生成器（新）
src/Services/Subscribe/VLESS.php          # VLESS 订阅（新）
src/Services/Subscribe.php                # 注册 vless 类型
src/Models/Node.php                       # sort=20 => VLESS (Xray)
src/Controllers/SubController.php         # 订阅类型白名单加 vless
resources/views/tabler/admin/node/create.tpl  # 接入类型下拉加 VLESS
resources/views/tabler/admin/node/edit.tpl    # 同上
tests/Integration/Services/XrayTest.php   # 回归测试（新）
```

验证方式：`php -l` 全绿 + 独立断言脚本 30+ 项全通过（生成配置与 deploy.sh 输出逐字段一致）。