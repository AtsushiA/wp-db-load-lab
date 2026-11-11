#!/usr/bin/env bash
# 負荷をかける： ./scripts/load.sh [パス=/random/] [req/秒=20] [時間=30s]
#   例） ./scripts/load.sh /random/ 30 60s
#        ./scripts/load.sh "/random/?dblab_strategy=idrange"
#        ./scripts/load.sh /random/category/lab-a/
source "$(dirname "$0")/_common.sh"
docker compose run --rm -T \
  -e TARGET="${1:-/random/}" -e RATE="${2:-20}" -e DURATION="${3:-30s}" \
  k6 run --quiet --no-usage-report /scripts/load.js
