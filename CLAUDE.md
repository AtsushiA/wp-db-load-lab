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
  - Prometheus の設定は `./prometheus` をディレクトリごとマウントする（ファイル単体だと、エディタで保存し直したときにコンテナ側が古い内容を見続ける）
- `prometheus/prometheus.yml`：`metric_relabel_configs` で、_total なしのカウンター（questions / created_tmp_tables / created_tmp_disk_tables / select_scan / rows_read）を `*_total` に改名する。改名しないと rate() に「metric might not be a counter」の PromQL info が付き、Grafana のパネルに ⚠ が出る
- `wordpress/mu-plugins/db-load-lab.php`：local / development 環境でのみ有効
  - `posts_pre_query` で `wp_is_random_content_query()` の SQL を受け取り、`ORDER BY RAND() LIMIT 0, 1` を外して各方式に渡す
  - 方式は rand / offset / idrange / cached。`?dblab_strategy=` か option `dblab_strategy` で切り替える
  - 単体エンドポイント `/?dblab=none|latest|rand|offset|idrange|cached[&go=1]`
  - レスポンスヘッダー `X-DBLab-Strategy/Queries/Pick-Ms`
- `scripts/php/seed.php`：INSERT ... SELECT で投稿を一括作成する（`post_content_filtered='dblab'` を目印にする）。カテゴリーは Lab A〜E
  - INSERT ... SELECT は InnoDB が AUTO_INCREMENT を倍々に先取りし、1 万件で約 6,000 個の欠番が出る。idrange が偏るので、バッチの前後で `ALTER TABLE wp_posts AUTO_INCREMENT = 1`（＝MAX(ID)+1 に詰める）を実行している
- `k6/load.js`：constant-arrival-rate（req/秒を固定）、redirects:0、Host ヘッダーを localhost:8888 にする
- `grafana/dashboards/wp-db-load.json`：Python で生成したもの（元スクリプトは同梱していない）。PromQL は MySQL の `innodb_row_ops_total{operation="read"}` を使い、MariaDB 向けに `rows_read_total` を `or` でフォールバックしている。メトリクス名は上の改名後のもの（`questions_total` など）を使う

## 検証状況

### ローカル実機（2026-11-11、Apple Silicon ＋ Docker、MySQL 8.4.13 arm64）

`./scripts/setup.sh` で実起動し、以下をすべて確認済み。

- ✔ `docker compose up`、wordpress:cli（UID 33）での seed とプラグインのインストール、`wp core update --version=nightly`（7.2-beta5-65399）、`/random/` の 302
- ✔ Grafana のプロビジョニング（データソース・ダッシュボード）と表示。ダッシュボードの全 13 本の PromQL がデータを返し、⚠ も出ない
- ✔ explain.sh の EXPLAIN ANALYZE、watch.sh、load.sh（20 req/s × 60 秒で失敗 0%、p95 約 20ms）
- ✔ idrange の偏り：seed 修正後、300 回中で同じ記事が選ばれたのは最大 3 回

1 万件・1 回実行時の読み取り（MySQL 8.4 で測定。`./scripts/explain.sh` で再現できる）：

| 方式 | rows_read | 内訳 |
|---|---|---|
| rand | 10,001 | read_next 10,001。一時テーブル 0、read_rnd_next 0 |
| offset | 10,001 ＋ 平均 5,000 | COUNT で read_next 10,001 ＋ LIMIT で read_next 平均 N/2 |
| idrange | 1 | read_key 1 |
| latest（参考） | 1 | read_key 1 |

- **MySQL 8.4 の `ORDER BY RAND() LIMIT 1` は一時テーブルを作らない**。EXPLAIN ANALYZE は `Index lookup on type_status_date → Filter: post_password = '' → Stream results → Sort: rand(), limit input to 1 row(s) per chunk`。候補を 1 回読みながら乱数最小の 1 行だけを残す（優先度付きキュー）。performance_schema の実クエリ 2,802 回分でも、一時テーブル 0、sort_rows 1
- そのため MySQL 8.4 では **offset のほうが rand より多く読む**（約 1.5 倍）
- `post_password = ''` は type_status_date インデックスに含まれないため、カバリングインデックスにならず、行本体を読む

### 以前のクラウド環境（Docker Hub に接続できない環境）

- ✔ 実物の WordPress 7.2-beta5 ＋ MariaDB 10.11 ＋ php -S で、mu-plugin の全方式、/random/category/、option による即時切り替え、seed（2.5 万件で 1.3 秒）
- ✔ 実物の mysqld_exporter 0.15.1 ＋ Prometheus 2.53.1 ＋ k6 0.53.0 で、ダッシュボードの全 PromQL がデータを返す
- ✔ `docker compose config`、promtool check config、`bash -n`、`php -l`

2.5 万件・1 回実行時の読み取り（MariaDB 10.11 で測定。MySQL 8.4 と違い、rand が一時テーブルを使う）：

| 方式 | 読み取り |
|---|---|
| rand | read_next 25001 ＋ read_rnd_next 25002 ＋ 一時テーブル 1 |
| offset | COUNT で read_rnd_next 約 25000 ＋ LIMIT で read_next 平均 N/2 |
| idrange | read_key 1 |

## ハマりどころ

- **手元に別アーキテクチャのイメージが残っていると、`up` はそれを使う**。今回は amd64 版の `mysql:8.4` がエミュレーションで動いていた。setup.sh は起動前に `docker compose --profile tools pull` するようにした
- **`Innodb_rows_read` はグローバル専用**で、`FLUSH STATUS` でもセッション値は取れない。explain.sh は実行前後の差分で測っている（負荷をかけながら実行すると他のリクエストの分が混ざる）
- **件数を減らして seed し直すと idrange が偏る**。前回の ID 範囲が空いたまま残り、その後ろにある自動生成のナビゲーション（wp_navigation）などが MAX(ID) を押し上げるため。読む行数の比較には影響しない。分布も見せるなら `docker compose down -v` してから作り直す
- k6 の最大応答時間が毎回 3 秒前後になる（最初の数リクエストと思われる。未調査）

## 次にやること

1. Grafana の見た目を調整する（パネル配置、しきい値）。実機で気づいた点：
   - 「読んだ行数 と クエリ数」：クエリ数の線が底に張り付く（右の Y 軸に分けたい）
   - 「クエリ別：1回の実行で調べた行数」：凡例が 4 行で切れていて名前順。肝心の RAND のクエリが見えない（値の大きい順にする）
   - 「Handler：行の読み方の内訳」：read_first / read_last / read_rnd など、ほぼ 0 の系列が凡例に並ぶ
   - 「一時テーブルとフルスキャン」：MySQL 8.4 では方式を変えてもほぼ動かないので、パネルを残すか検討する
2. 必要なら件数ごとの比較結果（1k / 10k / 50k）をスライド用にまとめる
