#!/usr/bin/env bash
# SSPanel 开发栈初始化（P7）：建库 → 导入设置 → 造测试数据
set -euo pipefail

dc() { docker compose -f docker/dev/docker-compose.dev.yml "$@"; }

echo "[1/4] 等待数据库就绪…"
i=0
while [ $i -lt 40 ]; do
  if dc exec -T db mariadb -uroot -psspanel -e 'SELECT 1' >/dev/null 2>&1; then break; fi
  i=$((i + 1))
  sleep 2
done

echo "[2/4] 建库（php xcat Migration new）"
dc exec -T php php xcat Migration new

echo "[3/4] 导入设置（config/settings.json → config 表）"
dc exec -T php php xcat Tool importSetting

echo "[4/4] 造测试数据（wumi 配置 / 用户 / 套餐 / VLESS 节点）"
dc exec -T php php docker/dev/seed_dev.php

echo "初始化完成。面板: http://localhost:18080"
