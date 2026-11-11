# 共通設定（各スクリプトから source される）
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."
# .env があればポート設定などを読み込む
if [ -f .env ]; then set -a; . ./.env; set +a; fi
WP_PORT="${WP_PORT:-8888}"

wp() { docker compose run --rm -T cli wp "$@"; }
mysql_root() { docker compose exec -T db mysql -uroot -proot wordpress "$@" 2>/dev/null; }

# WordPress 7.2 以降なら /random/ 経由、それ未満なら単体エンドポイントで比較する
random_url() {
  local strategy="$1"
  if [ "$(curl -s -o /dev/null -w '%{http_code}' "http://localhost:${WP_PORT}/random/")" = "302" ]; then
    echo "/random/?dblab_strategy=${strategy}"
  else
    echo "/?dblab=${strategy}"
  fi
}
