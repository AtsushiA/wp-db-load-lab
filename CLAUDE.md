# WordPress DB 負荷ラボ — 引き継ぎメモ

ミートアップ（関西の WordPress コミュニティ）で「アクセスが DB にかける負荷」をリアルタイムに見せるための学習環境。
題材は WordPress 7.2 の `/random/`（ランダムな記事への 302 リダイレクト）。使い方は README.md を参照。応答は日本語で。

## 背景（調査済みの事実）

- dev note: https://make.wordpress.org/core/2026/11/05/your-content-is-going-to-get-more-random/
- 7.2-beta5 のコアのソースで確認した内容：
  - `WP::handle_random()`（wp-includes/class-wp.php）がメインクエリを実行する。実行中は `pre_get_posts` / `posts_orderby` / `post_limits` に優先度 20 のコールバックを付ける
  - 選択 SQL は `SELECT wp_posts.ID ... WHERE post_password = '' AND post_type IN (...) AND post_status = 'publish' ORDER BY RAND() LIMIT 0, 1`
    - `no_found_rows=true`。split_the_query により、ID だけ取ってから `SELECT wp_posts.* WHERE ID IN (...)` を発行する
  - `wp_random_content_redirect()`：`nocache_headers()`、`X-Robots-Tag: noindex, follow`、`wp_safe_redirect( ..., 302 )`
  - 無効化は `add_filter( 'wp_enable_random_content_redirect', '__return_false' );`
  - 対象の投稿タイプは `register_post_type()` の `randomable` 引数で決まる。既定は publicly_queryable かつ has_archive。post は対象、page と attachment は対象外
  - 9 月にオプトイン版（`?random` クエリ、COUNT＋OFFSET 方式、r63767）が入ったが、r63906 でリバートされた

## 構成

- `compose.yaml`：mysql:8.4 / wordpress:php8.3-apache（:8888）/ wordpress:cli（profile tools）/ prom/mysqld-exporter:v0.15.1 / prometheus v2.53.1（:9090、2 秒ごとに収集）/ grafana 11.2.0（:3000、匿名 Admin）/ grafana/k6 0.53.0（profile tools）
- `wordpress/mu-plugins/db-load-lab.php`：local / development 環境でのみ有効
  - `posts_pre_query` で `wp_is_random_content_query()` の SQL を受け取り、`ORDER BY RAND() LIMIT 0, 1` を外して各方式に渡す
  - 方式は rand / offset / idrange / cached。`?dblab_strategy=` か option `dblab_strategy` で切り替える
  - 単体エンドポイント `/?dblab=none|latest|rand|offset|idrange|cached[&go=1]`
  - レスポンスヘッダー `X-DBLab-Strategy/Queries/Pick-Ms`
- `scripts/php/seed.php`：INSERT ... SELECT で投稿を一括作成する（`post_content_filtered='dblab'` を目印にする）。カテゴリーは Lab A〜E
- `k6/load.js`：constant-arrival-rate（req/秒を固定）、redirects:0、Host ヘッダーを localhost:8888 にする
- `grafana/dashboards/wp-db-load.json`：Python で生成したもの（元スクリプトは同梱していない）。PromQL は MySQL の `innodb_row_ops_total{operation="read"}` を使い、MariaDB 向けに `rows_read` を `or` でフォールバックしている

## 検証状況

クラウド環境（Docker Hub に接続できない）で、部品ごとに以下を検証済み。

- ✔ 実物の WordPress 7.2-beta5 ＋ MariaDB 10.11 ＋ php -S で、mu-plugin の全方式、/random/category/、option による即時切り替え、seed（2.5 万件で 1.3 秒）
- ✔ 実物の mysqld_exporter 0.15.1 ＋ Prometheus 2.53.1 ＋ k6 0.53.0 で、ダッシュボードの全 PromQL がデータを返す
- ✔ `docker compose config`、promtool check config、`bash -n`、`php -l`
- ✖ **未検証**：`docker compose up` による実起動（MySQL 8.4 イメージ、wordpress:cli の UID 33、`wp core update --version=nightly`）、Grafana の表示とプロビジョニング、explain.sh の EXPLAIN ANALYZE（MySQL 8.4 専用）、watch.sh

2.5 万件・1 回実行時の読み取り行数（MariaDB で測定）：

| 方式 | 読み取り |
|---|---|
| rand | read_next 25001 ＋ read_rnd_next 25002 ＋ 一時テーブル 1 |
| offset | COUNT で read_rnd_next 約 25000 ＋ LIMIT で read_next 平均 N/2 |
| idrange | read_key 1 |

`post_password = ''` は type_status_date インデックスに含まれないため、カバリングインデックスにならず、行本体を読む。

## 次にやること

1. `./scripts/setup.sh` で実際に起動し、上の未検証項目を確認する
2. Grafana の見た目を調整する（パネル配置、しきい値）
3. 必要なら件数ごとの比較結果（1k / 10k / 50k）をスライド用にまとめる
