#!/usr/bin/env bash
# 方式を順番に流して比較する（Grafana を横に開いておくと段差が見える）
#   ./scripts/compare.sh [req/秒=20] [各方式の時間=30s] [休憩秒=15]
source "$(dirname "$0")/_common.sh"
RATE="${1:-20}"; DURATION="${2:-30s}"; REST="${3:-15}"

targets=("/?dblab=latest")
for s in rand offset idrange cached; do targets+=("$(random_url "$s")"); done

echo "流量 ${RATE} req/s × ${DURATION}、間に ${REST} 秒休憩"
for t in "${targets[@]}"; do
  echo "──────── $(date +%H:%M:%S) 開始"
  ./scripts/load.sh "$t" "$RATE" "$DURATION"
  sleep "$REST"
done
