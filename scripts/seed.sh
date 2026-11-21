#!/usr/bin/env bash
# 投稿数を変える： ./scripts/seed.sh 50000 [本文KB=2] [1 記事あたりのリビジョン数=0]
#   リビジョン数を増やすと ID の密度が下がり、sample 方式（7.2 RC のコア）でランダムな ID が外れやすくなる
#   例） ./scripts/seed.sh 10000 2 20   … ID の密度 約 5%
source "$(dirname "$0")/_common.sh"
wp eval-file /lab/seed.php "${1:-10000}" "${2:-2}" "${3:-0}"
