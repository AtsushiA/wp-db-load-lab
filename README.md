# WordPress DB 負荷ラボ

WordPress へのアクセスが DB にどんな負荷をかけているかを、ローカルでリアルタイムに可視化するミートアップ用の環境です。
題材は WordPress 7.2 で入る `/random/`（ランダムな記事へのリダイレクト）です。

```
 k6（負荷）──▶ WordPress ──▶ MySQL 8.4 ◀── mysqld_exporter ◀── Prometheus ◀── Grafana
                  └ mu-plugin「DB Load Lab」で選び方を切り替え
```

| URL | 中身 |
|---|---|
| http://localhost:8888 | WordPress（admin / admin） |
| http://localhost:3000 | Grafana（ログイン不要。ダッシュボード「WordPress DB 負荷ラボ」） |
| http://localhost:9090 | Prometheus |

> ⚠ ローカル学習専用です。パスワードは固定値なので、公開サーバーでは動かさないでください。

---

## 1. 必要なもの

- Docker Desktop（または Docker Engine＋Compose v2）。メモリは 4GB 以上を割り当てておくと安心です
- macOS／Linux／Windows（WSL2）。Apple Silicon でも動きます
- 空きポート：8888、3000、9090、3307（変えたいときは「8. 困ったとき」を参照）

wp-env は使いません。wp-env は自前でコンテナを管理するので、監視系を同じネットワークに入れにくいためです。
WordPress・MySQL・監視のすべてを、この `compose.yaml` 1 枚にまとめています。

## 2. セットアップ（初回 5 分ほど）

```bash
cd wp-db-load-lab
./scripts/setup.sh          # 投稿 1 万件で準備。件数を変えるなら ./scripts/setup.sh 50000
```

setup.sh が行うこと：

1. コンテナを起動する
2. WordPress をインストールする
3. **WordPress を nightly（7.2 開発版）に更新する**（`/random/` を使うため）
4. パーマリンクを `/%postname%/` にする
5. Query Monitor を入れる
6. 学習用の投稿を SQL で一括作成する（1 万件で数秒）

7.2 の正式リリース後は、次のように最新の正式版を使えます。

```bash
WP_CORE_VERSION=latest ./scripts/setup.sh
```

最後に「✔ /random/ が使えます」と出れば準備完了です。
7.2 未満のままでも、単体エンドポイント `/?dblab=rand` などで同じ比較ができます。

## 3. まず見てみる

1. Grafana（http://localhost:3000）を開きます。2 秒ごとに更新されます
2. 別のターミナルで負荷をかけます

```bash
./scripts/load.sh /random/            # 20 req/秒 × 30 秒
./scripts/load.sh /random/ 50 60s     # 50 req/秒 × 60 秒
```

ダッシュボードの「読んだ行数 / 秒」が跳ね上がり、「クエリ別：1回の実行で調べた行数」の先頭に `ORDER BY RAND()` の行が出てきます。

## 4. 選び方の 4 方式

同梱の mu-plugin（`wordpress/mu-plugins/db-load-lab.php`）が、`/random/` の「1 件の選び方」を差し替えます。
WHERE 句（投稿タイプ・カテゴリー・著者）はコアが組み立てたものをそのまま使うので、`/random/category/lab-a/` でも同じ条件で比べられます。

| 方式 | やり方 | 1 回で読む行数 | 弱点 |
|---|---|---|---|
| `rand`（7.2 コア） | `ORDER BY RAND() LIMIT 0, 1` | 候補件数（MariaDB では一時テーブル経由で約 2 倍） | 件数に比例して重くなる |
| `offset` | `COUNT(*)` → `LIMIT 乱数, 1` | 候補件数＋平均で半分 | 並べ替えはしないが、MySQL 8.4 では rand より多く読む |
| `idrange` | MIN〜MAX の ID から乱数を選び、`ID >= x` の最初の 1 件 | ほぼ 1 行 | ID に歯抜けがあると、選ばれやすい記事に偏る |
| `cached` | 候補の ID 一覧を transient に 5 分キャッシュし、PHP で選ぶ | キャッシュ切れのときだけ候補件数分 | 一覧の鮮度、件数が多いとオプション行が大きくなる |

> 1 万件の MySQL 8.4 で 1 回ずつ実行して測った値です（`./scripts/explain.sh` で再現できます）。
> rand は候補の 10,001 行をインデックスで全部読み、乱数がいちばん小さい 1 行だけを手元に残します（一時テーブルは作りません）。offset は COUNT で 10,001 行、LIMIT で平均 5,000 行を読むので、**rand より多く読みます**。idrange は 1 行で済みます。
> MariaDB 10.11 では、rand は全候補を一時テーブルに書いてから並べ替えるため、2.5 万件で約 5 万行（読み取り＋一時テーブルの読み直し）になりました。同じ SQL でも、DB エンジンによって実行のされ方が変わります。
> **「ORDER BY RAND() をやめれば解決」ではない**ことがわかるのがポイントです。

切り替え方は 2 通りあります。

```bash
# そのリクエストだけ切り替える
./scripts/load.sh "/random/?dblab_strategy=idrange"

# サイト全体で切り替える（負荷をかけたまま実行すると、グラフがその場で変わる）
./scripts/strategy.sh offset
./scripts/strategy.sh rand      # 元に戻す
```

レスポンスヘッダー `X-DBLab-Strategy` / `X-DBLab-Queries` / `X-DBLab-Pick-Ms` で、使われた方式・クエリ数・選択にかかった時間を確認できます。

```bash
curl -sI "http://localhost:8888/random/?dblab_strategy=cached" | grep -i x-dblab
```

### 単体エンドポイント（7.2 未満でも可）

| URL | 内容 |
|---|---|
| `/?dblab=none` | WordPress の起動だけ（基準線） |
| `/?dblab=latest` | 最新記事 1 件（普通のインデックス検索） |
| `/?dblab=rand` `/?dblab=offset` `/?dblab=idrange` `/?dblab=cached` | 各方式 |
| 上記に `&go=1` を付ける | 選んだ記事へ 302 リダイレクト |

## 5. スクリプト一覧

| コマンド | 内容 |
|---|---|
| `./scripts/setup.sh [件数]` | 初回セットアップ |
| `./scripts/seed.sh 50000 [本文KB]` | 学習用投稿を指定件数に作り直す |
| `./scripts/load.sh [パス] [req/秒] [時間]` | k6 で負荷をかける（リダイレクトは追わない） |
| `./scripts/compare.sh [req/秒] [時間] [休憩秒]` | latest → rand → offset → idrange → cached を順番に流す |
| `./scripts/strategy.sh rand\|offset\|idrange\|cached` | `/random/` の方式をサイト全体で切り替える |
| `./scripts/explain.sh` | 各方式の SQL を 1 回ずつ実行して、読んだ行数を比べる＋EXPLAIN ANALYZE |
| `./scripts/watch.sh` | Grafana なしで、ターミナルに 1 秒ごとのカウンタ増分を流す |
| `./scripts/reset-stats.sh` | クエリ別統計とカウンタ、transient をリセットする |

**負荷は「VU 数」ではなく「req/秒」で固定しています**（k6 の constant-arrival-rate）。
同じ流量をかけないと、速い方式ほどたくさんリクエストをさばいて DB の数字が大きく見え、比較になりません。
この点もミートアップで話せる題材です。

## 6. ダッシュボードの見方

| パネル | 何を見るか |
|---|---|
| クエリ数 / 秒 | どの方式でもほぼ同じ（＝クエリ数だけ見ていても重さはわからない） |
| 読んだ行数 / 秒 | ここが桁違いに増えるのが重いクエリのサイン（`Innodb_rows_read`） |
| 1クエリあたり読んだ行数 | 普通のページなら数〜数十。数千を超えたら要注意 |
| 同時実行中のクエリ | DB が詰まり始めると増える。流量を上げていくと rand だけ先に伸びる |
| Handler：行の読み方の内訳 | `read_key`＝インデックスで 1 点検索、`read_next`＝インデックス順に次々読む、`read_rnd_next`＝テーブル（一時テーブルを含む）を頭から全部読む |
| 一時テーブルとフルスキャン | MariaDB では `ORDER BY RAND()` が毎回一時テーブルを作る。MySQL 8.4 では作らないので、方式を変えてもほぼ動かない |
| クエリ別：1回の実行で調べた行数 | performance_schema のダイジェスト別。犯人のクエリが一目でわかる |
| クエリ別：合計実行時間 | DB が 1 秒のうち何秒をそのクエリに使っているか |

## 7. ミートアップでの進行例（20〜30 分）

1. **問い**：「`/random/` を連打したら重くなる？」と問いかけ、`/random/` の仕組みを説明します（コアの `WP::handle_random()` が `ORDER BY RAND() LIMIT 0, 1` を実行する）
2. **普通のアクセス**：`./scripts/load.sh "/?dblab=latest"` で、1 クエリあたり数行しか読まない状態を見せます
3. **`/random/`**：`./scripts/load.sh /random/ 20 60s` を実行し、読んだ行数が桁違いに増える様子を見せます
4. **件数を増やす**：`./scripts/seed.sh 50000` → 同じ負荷をかけ、件数に比例して重くなることを見せます
5. **流量を上げる**：`./scripts/load.sh /random/ 80 60s` を実行し、同時実行中のクエリと応答時間が伸びる様子を見せます
6. **仕組みを見る**：`./scripts/explain.sh` の EXPLAIN ANALYZE で「候補を全件読む（`rows=10001`）→ 乱数で並べ替えて 1 件」を確認します
7. **直してみる**：負荷をかけたまま `./scripts/strategy.sh offset` → `idrange` → `cached` と切り替え、グラフの変化を見ます
   - offset で下がらない（むしろ増える）のは「なぜ？」という問いにつながります
8. **まとめ**：
   - 不要なら mu-plugin で `add_filter( 'wp_enable_random_content_redirect', '__return_false' );` を入れる
   - 残すなら `/random/` へのレート制限や robots.txt を検討する
   - コアは 302＋`Cache-Control: no-cache`＋`X-Robots-Tag: noindex` を返すため、CDN でのキャッシュは効かない

ブラウザで `/random/` を開くと、Query Monitor でリクエスト内の SQL も見られます。リダイレクト前のページなので、`/?dblab=rand` のほうが見やすいです。

## 8. 困ったとき

- **ポートが使われている**：`.env` ファイルを作り、`WP_PORT=8080` / `GRAFANA_PORT=3001` / `PROM_PORT=9091` / `DB_PORT=3308` のように指定します。compose とスクリプトの両方がこの値を読みます（初回セットアップ前に作ってください。WP_PORT はサイト URL にも使われます）
- **Grafana に何も出ない**：http://localhost:9090/targets で `mysql` が UP か確認します。DOWN なら `docker compose logs exporter` を見てください
- **最初から作り直す**：`docker compose down -v` → `./scripts/setup.sh`
- **終了**：`docker compose down`（データを残す）／`docker compose down -v`（データも消す）
- **遅いクエリをファイルで見る**：`docker compose exec db tail -f /var/lib/mysql/slow.log`（0.05 秒以上を記録）
- **Docker Desktop for Mac で全体が遅い**：Docker Desktop の設定で CPU とメモリの割り当てを増やしてください。数字の絶対値は環境によって変わりますが、方式どうしの差は再現します

## 9. 構成ファイル

```
compose.yaml                         全サービス
mysql/conf.d/lab.cnf                 performance_schema・スロークエリログ
mysql/init/01-exporter.sql           監視ユーザー
prometheus/prometheus.yml            2 秒ごとに収集
grafana/provisioning/                データソースとダッシュボードの自動登録
grafana/dashboards/wp-db-load.json   ダッシュボード
wordpress/mu-plugins/db-load-lab.php 方式の切り替え・単体エンドポイント（local 環境でしか動かない）
scripts/php/seed.php                 投稿の一括作成
k6/load.js                           負荷シナリオ
scripts/*.sh                         操作用スクリプト
```

PMM（Percona Monitoring and Management）を使う方法もあります。
今回は「何を測っているか」が追いやすいよう、Prometheus と Grafana で必要なパネルだけに絞りました。
