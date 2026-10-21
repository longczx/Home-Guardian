#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
# An isolated project: no published ports, production credentials or production volumes.
project="${HG_TEST_PROJECT:-hg-release-check}"
compose=(docker compose -p "$project" -f docker-compose.test.yml)
trap '"${compose[@]}" down >/dev/null' EXIT
"${compose[@]}" up -d redis postgres mailpit
"${compose[@]}" build php
"${compose[@]}" run --rm php composer install --no-scripts --no-interaction --prefer-dist
"${compose[@]}" run --rm php sh -c 'find app config database tests -name "*.php" -print0 | xargs -0 -n1 php -l >/dev/null && vendor/bin/phpunit --fail-on-warning'
"${compose[@]}" run --rm node sh -c 'npm ci --no-audit --no-fund && npm run lint -- --quiet && npm test && npm run type-check && npm run build:h5:local && npm run build:mp-weixin'
"${compose[@]}" run --rm firmware sh -c 'pip install platformio==6.1.19 && mkdir -p /tmp/hg-firmware && cp -r src include platformio.ini /tmp/hg-firmware/ && cp include/config.example.h /tmp/hg-firmware/include/config.h && cd /tmp/hg-firmware && pio run'

python3 tests/Operations/runtime_smoke.py
