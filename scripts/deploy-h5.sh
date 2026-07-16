#!/usr/bin/env bash
#
# Home Guardian - uni-app H5 一键部署
#
# 流程：构建 uniapp H5（产物落 public/app）→ 重建 webman 镜像（烤入 public）
#      → 重建容器 → 健康检查 /app/。
#
# 用法（在仓库根目录执行）：
#   bash scripts/deploy-h5.sh
#
# 依赖：docker + docker compose。无本机 Node 时自动用 node:20-alpine 容器构建。
#
set -euo pipefail

# 切到仓库根目录（脚本在 scripts/ 下）
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

COMPOSE_FILE="docker-compose.yml"
BASE_URL="${HG_BASE_URL:-http://localhost}"   # 健康检查基址，可用环境变量覆盖

log() { printf '\033[1;34m[deploy-h5]\033[0m %s\n' "$*"; }
die() { printf '\033[1;31m[deploy-h5] 失败:\033[0m %s\n' "$*" >&2; exit 1; }

command -v docker >/dev/null 2>&1 || die "未找到 docker"

# ── 1. 构建 H5 ────────────────────────────────────────────────
log "构建 uni-app H5（输出 public/app）…"
if command -v npm >/dev/null 2>&1; then
  log "使用本机 Node：$(node -v)"
  ( cd uniapp && npm install --no-audit --no-fund && npm run build:h5 )
else
  log "本机无 Node，使用 node:20-alpine 容器构建"
  docker run --rm -v "$ROOT":/work -w /work/uniapp node:20-alpine \
    sh -c "npm install --no-audit --no-fund && npm run build:h5"
fi

[ -f public/app/index.html ] || die "H5 构建产物缺失（public/app/index.html）"
log "H5 构建完成"

# ── 2. 重建镜像 + 重建容器 ────────────────────────────────────
log "重建 webman 镜像（烤入 public/app）…"
docker compose -f "$COMPOSE_FILE" build webman

log "重建容器…"
docker compose -f "$COMPOSE_FILE" up -d --force-recreate webman nginx

# ── 3. 数据库迁移（后端有新迁移时自动补跑）────────────────────
log "执行数据库迁移…"
# 等 webman 起来再迁移
for i in $(seq 1 15); do
  docker compose -f "$COMPOSE_FILE" exec -T webman php -r 'exit(0);' >/dev/null 2>&1 && break
  sleep 2
done
docker compose -f "$COMPOSE_FILE" exec -T webman php webman migrate:run || die "数据库迁移失败"

# ── 4. 健康检查 ───────────────────────────────────────────────
log "等待服务就绪…"
ok=0
for i in $(seq 1 20); do
  code="$(curl -s -o /dev/null -w '%{http_code}' "$BASE_URL/app/" || true)"
  if [ "$code" = "200" ]; then ok=1; break; fi
  sleep 2
done

[ "$ok" = "1" ] || die "健康检查未通过：$BASE_URL/app/ 未返回 200"

api="$(curl -s "$BASE_URL/api/health" || true)"
log "✅ 部署完成"
log "   H5 客户端: $BASE_URL/app/"
log "   API 健康:  $api"
