# SSPanel-UIM 本地开发/验证栈（P7）

用于把面板真正跑起来，端到端验证 wumi 集成（身份桥 / 节点桥 / 商用接口）。
**仅供本地验证，不是生产部署方案**（生产按官方文档走 nginx + php-fpm + MariaDB + Redis）。

## 组成

| 服务 | 说明 | 端口 |
|---|---|---|
| php | PHP 8.3-CLI + 面板所需扩展 + composer，用内置服务器跑 `public/index.php` | 18080 |
| db | MariaDB 10.11（关闭严格模式） | 13306 |
| redis | Redis 7 | 16379 |

## 步骤

```bash
# 1) 起容器（首次会构建 php 镜像）
docker compose -f docker/dev/docker-compose.dev.yml up -d --build

# 2) 生成配置（首次）
cp config/.config.example.php config/.config.php     # 按需改 db_host=db / redis_host=redis
cp config/appprofile.example.php config/appprofile.php

# 3) 装依赖
docker compose -f docker/dev/docker-compose.dev.yml exec -T php composer install

# 4) 建库 + 导入设置 + 造测试数据
bash docker/dev/bootstrap_dev.sh

# 5) 冒烟测试（wumi 内部 API 全链路）
bash docker/dev/smoke_wumi_api.sh
```

## 说明

- `docker/dev/seed_dev.php` 会写入 dev 用的 `wumi_api_key` / `wumi_jwt_secret`，
  并建一个绑定 `dev-wumi-user-key` 的用户、一个测试套餐、一个 VLESS 节点。
- 面板地址 <http://localhost:18080>；`/wumi/api/v1/*` 需要
  `X-Wumi-Api-Key`（机器密钥），用户相关再加 `X-Wumi-User-Key`（wumi 用户公钥）。
- 重置：`docker compose -f docker/dev/docker-compose.dev.yml down -v` 后重来。
