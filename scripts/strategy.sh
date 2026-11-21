#!/usr/bin/env bash
# /random/ の選び方をサイト全体で切り替える（負荷をかけたまま切り替えると Grafana で変化が見える）
#   ./scripts/strategy.sh sample | rand | offset | idrange | cached
#     sample … 7.2 RC 以降のコア（変更後）／ rand … 7.2 beta までのコア（変更前）
source "$(dirname "$0")/_common.sh"
case "${1:-}" in
  sample|rand|offset|idrange|cached) wp option update dblab_strategy "$1" ;;
  *) echo "使い方: $0 sample|rand|offset|idrange|cached（現在: $(wp option get dblab_strategy 2>/dev/null || echo sample)）"; exit 1 ;;
esac
