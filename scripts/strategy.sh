#!/usr/bin/env bash
# /random/ の選び方をサイト全体で切り替える（負荷をかけたまま切り替えると Grafana で変化が見える）
#   ./scripts/strategy.sh rand | offset | idrange | cached
source "$(dirname "$0")/_common.sh"
case "${1:-}" in
  rand|offset|idrange|cached) wp option update dblab_strategy "$1" ;;
  *) echo "使い方: $0 rand|offset|idrange|cached（現在: $(wp option get dblab_strategy 2>/dev/null || echo rand)）"; exit 1 ;;
esac
