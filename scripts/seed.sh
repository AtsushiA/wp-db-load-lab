#!/usr/bin/env bash
# 投稿数を変える： ./scripts/seed.sh 50000 [本文KB=2]
source "$(dirname "$0")/_common.sh"
wp eval-file /lab/seed.php "${1:-10000}" "${2:-2}"
