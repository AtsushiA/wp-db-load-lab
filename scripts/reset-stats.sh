#!/usr/bin/env bash
# クエリ別統計とステータスカウンタをリセット（デモの区切りに）
source "$(dirname "$0")/_common.sh"
mysql_root -e "TRUNCATE performance_schema.events_statements_summary_by_digest; FLUSH STATUS;"
wp transient delete --all >/dev/null 2>&1 || true
echo "リセットしました"
