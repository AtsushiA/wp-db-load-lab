#!/usr/bin/env bash
# 初回セットアップ： ./scripts/setup.sh [投稿数=10000]
source "$(dirname "$0")/_common.sh"
POSTS="${1:-10000}"
# WordPress 7.2 正式リリースまでは nightly（開発版）を入れる。リリース後は WP_CORE_VERSION=latest で OK
WP_CORE_VERSION="${WP_CORE_VERSION:-nightly}"

# 先に pull しておく。手元に別アーキテクチャ（Apple Silicon 上の amd64 など）の同名タグが残っていると
# `up` はそれをそのまま使い、エミュレーションで動いて計測値がゆがむため。オフラインでも先に進む
echo "▶ イメージを取得"
docker compose --profile tools pull --ignore-pull-failures || true

echo "▶ コンテナを起動"
docker compose up -d

echo "▶ WordPress のファイル展開を待っています"
for _ in $(seq 1 60); do
  if wp core version >/dev/null 2>&1; then break; fi
  sleep 2
done

if ! wp core is-installed >/dev/null 2>&1; then
  echo "▶ WordPress をインストール"
  wp core install --url="http://localhost:${WP_PORT}" --title="DB Load Lab" \
    --admin_user=admin --admin_password=admin --admin_email=admin@example.com --skip-email
fi

if [ "$WP_CORE_VERSION" != "image" ]; then
  echo "▶ WordPress を ${WP_CORE_VERSION} に更新"
  wp core update --version="$WP_CORE_VERSION" --force
  wp core update-db
fi

echo "▶ パーマリンクを /%postname%/ に"
wp rewrite structure '/%postname%/'
wp rewrite flush

echo "▶ Query Monitor を入れる（1リクエスト内の SQL を見る用）"
wp plugin install query-monitor --activate || echo "  （スキップ：ネットワークを確認してください）"

echo "▶ 学習用の投稿を ${POSTS} 件作成"
wp eval-file /lab/seed.php "$POSTS"

wp option update dblab_strategy rand >/dev/null
echo
wp core version --extra | head -1
wp eval 'echo function_exists("wp_is_random_content_redirect_enabled") && wp_is_random_content_redirect_enabled() ? "✔ /random/ が使えます\n" : "✖ /random/ がありません（7.2 未満）。/?dblab=rand などで比較できます\n";'
cat <<MSG

準備完了
  WordPress   http://localhost:${WP_PORT}   （admin / admin）
  Grafana     http://localhost:${GRAFANA_PORT:-3000}   （ダッシュボード「WordPress DB 負荷ラボ」）
  Prometheus  http://localhost:${PROM_PORT:-9090}

次は ./scripts/load.sh /random/ で負荷をかけてみてください。
MSG
