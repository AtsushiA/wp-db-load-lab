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
- **7.2 RC で選び方が変わった**：r65542（2026-11-17、RC1 r65558 の直前。Trac #66654、GitHub 3943b1ddd1「Query: Select random redirect posts by random post ID.」）。RC1・RC2（r65604）に含まれる
  - `wp_random_content_pre_get_posts()` が `random_sample_ids` に `wp_get_random_content_sample_ids()` の結果（1〜MAX(ID) から重複なし 50 個）を入れる。MAX(ID) は object cache（グループ `random-content`、キー `max_post_id`）に 10 分
  - `posts_where`（優先度 20）が WHERE 末尾に ` AND wp_posts.ID IN (...)` を足し、`posts_orderby` / `post_limits` は空文字、`posts_fields` は `wp_posts.ID`
  - `wp_random_content_posts_pre_query()`（`posts_pre_query` 優先度 20）が SQL を実行し、当たりをシャッフルして `get_post()` で公開・パスワードなし・対象投稿タイプかを確かめて選ぶ。外れたら ID を引き直して最大 3 回
  - 3 回外れたら、ID 条件を `1=1` に、`post_password = ''` を外して `ORDER BY RAND() LIMIT 1000` の「プール」を作り、object cache に 10 分（有効期限は 1 時間保存。ロックと古いプールの使い回しあり）。キーは ID 条件を除いた SQL の md5
- beta5 → RC の間にほかにも変更あり：常にリダイレクト（`wp_redirect()` に変更、cdfa334178）、リダイレクトは `template_redirect` の優先度 100 で送る（f7c65116f9）、検索に `random` が付いたら検索結果へリダイレクト（0239f9b5de）

## 構成

- `compose.yaml`：mysql:8.4 / wordpress:php8.3-apache（:8888）/ wordpress:cli（profile tools）/ prom/mysqld-exporter:v0.15.1 / prometheus v2.53.1（:9090、2 秒ごとに収集）/ grafana 11.2.0（:3000、匿名 Admin）/ grafana/k6 0.53.0（profile tools）
  - Prometheus の設定は `./prometheus` をディレクトリごとマウントする（ファイル単体だと、エディタで保存し直したときにコンテナ側が古い内容を見続ける）
- `prometheus/prometheus.yml`：`metric_relabel_configs` で、_total なしのカウンター（questions / created_tmp_tables / created_tmp_disk_tables / select_scan / rows_read）を `*_total` に改名する。改名しないと rate() に「metric might not be a counter」の PromQL info が付き、Grafana のパネルに ⚠ が出る
- `wordpress/mu-plugins/db-load-lab.php`：local / development 環境でのみ有効。`db-load-lab/` の 3 ファイルを読み込むだけ
  - `strategies.php`：方式の実装。方式は sample（既定。7.2 RC のコア＝変更後）/ rand（7.2 beta のコア＝変更前）/ offset / idrange / cached。`?dblab_strategy=` か option `dblab_strategy` で切り替える。sample の 50・3・1000 はコアと値をそろえている
  - `random-redirect.php`：`posts_pre_query` 優先度 10 で `/random/` の SQL を受け取り、beta 形（`ORDER BY RAND() LIMIT 0, 1`）でも RC 形（` AND wp_posts.ID IN (...)`）でも、それを外した「候補一覧の SQL」を作って各方式に渡す。sample はコアに `wp_random_content_posts_pre_query` があれば何もせずコアに任せ（優先度 21 で所要時間を記録）、なければ再現版で選ぶ。想定外の SQL ならコアに任せ、ヘッダーは `core`
  - `endpoint.php`：単体エンドポイント `/?dblab=none|latest|sample|rand|offset|idrange|cached[&go=1]`。sample は再現版
  - レスポンスヘッダー `X-DBLab-Strategy/Queries/Pick-Ms`（`wp_redirect` フィルターで付ける）
  - RC のコアでは、どの方式でも `pre_get_posts` で MAX(ID) が 1 回走る（コアがサンプル ID を作るため）
- `scripts/php/seed.php`：INSERT ... SELECT で投稿を一括作成する（`post_content_filtered='dblab'` を目印にする）。カテゴリーは Lab A〜E（post_name の連番 % 5）
  - 3 つ目の引数で 1 記事あたりのリビジョン数（最大 99）。本文が空のリビジョンを各記事の直後に並べ（`ORDER BY n, r`）、post_parent は post_name でひも付ける。最後に「ID の密度」（公開済み投稿数 / MAX(ID)）を表示する
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
| sample | 1 ＋ 最大 50 | MAX(ID) で 1 ＋ `ID IN (50 個)` で read_key 50（存在する ID の数だけ読む） |
| sample のプール | 10,001 | パスワード条件なし `ORDER BY RAND() LIMIT 1000`。read_next 10,001（インデックスだけで読む）。カテゴリー別は GROUP BY で一時テーブルあり |
| offset | 10,001 ＋ 平均 5,000 | COUNT で read_next 10,001 ＋ LIMIT で read_next 平均 N/2 |
| idrange | 1 | read_key 1 |
| latest（参考） | 1 | read_key 1 |

- **MySQL 8.4 の `ORDER BY RAND() LIMIT 1` は一時テーブルを作らない**。EXPLAIN ANALYZE は `Index lookup on type_status_date → Filter: post_password = '' → Stream results → Sort: rand(), limit input to 1 row(s) per chunk`。候補を 1 回読みながら乱数最小の 1 行だけを残す（優先度付きキュー）。performance_schema の実クエリ 2,802 回分でも、一時テーブル 0、sort_rows 1
- そのため MySQL 8.4 では **offset のほうが rand より多く読む**（約 1.5 倍）
- `post_password = ''` は type_status_date インデックスに含まれないため、カバリングインデックスにならず、行本体を読む

### 7.2 RC での変更の比較（2026-11-21、7.2-RC2-65732 の nightly、MySQL 8.4.13）

- ✔ mu-plugin 1.1.0 の全方式が beta5（7.2-beta5-65399）と RC2 の両方のコアで動く。`/random/` と `/random/category/lab-a/`、単体エンドポイント
- ✔ 1 万件・1 リクエストあたりの Innodb_rows_read（WordPress 本体の約 160 行を含む）：sample 187 / rand 10,162（`/random/`）、sample 199 / rand 12,170（lab-a）
- ✔ `seed.sh 10000 2 20`（リビジョン 20 万件、7 秒、ID の密度 4.5%）で各 200 リクエスト：
  - `/random/`：sample は主キー検索 平均 1.14 回（1 回 48 行）、プール作成 0 回。rand は毎回 10,001 行
  - `/random/category/lab-a/`（密度 0.9%）：sample は主キー検索 平均 2.06 回、**プール作成 28%**（1 回 5,000 行＋一時テーブル）。rand は毎回 4,001 行＋一時テーブル。3 回外れる確率の理論値は約 25%
- 永続オブジェクトキャッシュがないので、コアの MAX(ID) とプールはリクエストごとに作り直される

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
2. 必要なら件数ごとの比較結果（1k / 10k / 50k）をスライド用にまとめる。7.2 RC の変更前後（rand / sample）と、ID の密度（リビジョン数）ごとの比較も入れる
3. 必要なら永続オブジェクトキャッシュ（Redis など）をつなぎ、sample のプール作成が 10 分に 1 回になる様子を比べられるようにする
4. mu-plugin の PHPUnit テストはない（テスト環境がないため、実リクエストで確認している）
