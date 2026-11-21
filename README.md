# WordPress DB 負荷ラボ

[English](README.en.md) | 日本語

WordPress へのアクセスが DB にどんな負荷をかけているかを、ローカルでリアルタイムに可視化するミートアップ用の環境です。
題材は WordPress 7.2 で入る `/random/`（ランダムな記事へのリダイレクト）です。
`/random/` の選び方は 7.2 RC で `ORDER BY RAND()` から「ランダムな ID を主キーで引く」方式に変わったので、**変更前と変更後を同じ条件で比べられます**（「[4.1 7.2 RC での変更](#41-72-rc-での変更変更前後の比較)」）。

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

1. イメージを取得し、コンテナを起動する
2. WordPress をインストールする
3. **WordPress を nightly（7.2 開発版。現在は RC2 以降）に更新する**（`/random/` を使うため）
4. パーマリンクを `/%postname%/` にする
5. Query Monitor を入れる
6. 学習用の投稿を SQL で一括作成する（1 万件で数秒）

7.2 の正式リリース後は、次のように最新の正式版を使えます。

```bash
WP_CORE_VERSION=latest ./scripts/setup.sh
```

最後に「✔ /random/ が使えます」と出れば準備完了です。カッコ内に、コアがどちらの選び方か（RC 以降の ID サンプリングか、beta までの `ORDER BY RAND()` か）が出ます。
7.2 未満のままでも、単体エンドポイント `/?dblab=sample` や `/?dblab=rand` などで同じ比較ができます。

## 3. まず見てみる

1. Grafana（http://localhost:3000）を開きます。2 秒ごとに更新されます
2. 別のターミナルで負荷をかけます

```bash
./scripts/load.sh "/random/?dblab_strategy=rand"      # 変更前（7.2 beta）の選び方。20 req/秒 × 30 秒
./scripts/load.sh "/random/?dblab_strategy=sample"    # 変更後（7.2 RC）の選び方
./scripts/load.sh /random/ 50 60s                     # サイト全体の設定（既定は sample）で 50 req/秒 × 60 秒
```

rand では、ダッシュボードの「読んだ行数 / 秒」が跳ね上がり、「クエリ別：1回の実行で調べた行数」の先頭に `ORDER BY RAND()` の行が出てきます。sample に変えると、桁違いに下がります。

## 4. 選び方の 5 方式

同梱の mu-plugin（`wordpress/mu-plugins/db-load-lab.php`）が、`/random/` の「1 件の選び方」を差し替えます。
WHERE 句（投稿タイプ・カテゴリー・著者）はコアが組み立てたものをそのまま使うので、`/random/category/lab-a/` でも同じ条件で比べられます。

| 方式 | やり方 | 1 回で読む行数 | 弱点 |
|---|---|---|---|
| `sample`（7.2 RC 以降のコア＝**変更後**。既定） | 1〜MAX(ID) からランダムな ID を 50 個選んで主キーで引き、候補に当たったものから選ぶ。3 回外れたら、候補を `ORDER BY RAND() LIMIT 1000` で選んだプールから選ぶ | 最大 50 行 × 引き直し回数 | 候補が ID の中でまばら（リビジョンが多い、小さいカテゴリーなど）だとプール作成に落ち、候補を全件読む |
| `rand`（7.2 beta までのコア＝**変更前**） | `ORDER BY RAND() LIMIT 0, 1` | 候補件数（MariaDB では一時テーブル経由で約 2 倍） | 件数に比例して重くなる |
| `offset` | `COUNT(*)` → `LIMIT 乱数, 1` | 候補件数＋平均で半分 | 並べ替えはしないが、MySQL 8.4 では rand より多く読む |
| `idrange` | MIN〜MAX の ID から乱数を選び、`ID >= x` の最初の 1 件 | ほぼ 1 行 | ID に歯抜けがあると、選ばれやすい記事に偏る |
| `cached` | 候補の ID 一覧を transient に 5 分キャッシュし、PHP で選ぶ | キャッシュ切れのときだけ候補件数分 | 一覧の鮮度、件数が多いとオプション行が大きくなる |

> 1 万件の MySQL 8.4 で 1 回ずつ実行して測った値です（`./scripts/explain.sh` で再現できます）。
> rand は候補の 10,001 行をインデックスで全部読み、乱数がいちばん小さい 1 行だけを手元に残します（一時テーブルは作りません）。sample は MAX(ID) で 1 行、ID 50 個の主キー検索で最大 50 行です。offset は COUNT で 10,001 行、LIMIT で平均 5,000 行を読むので、**rand より多く読みます**。idrange は 1 行で済みます。
> MariaDB 10.11 では、rand は全候補を一時テーブルに書いてから並べ替えるため、2.5 万件で約 5 万行（読み取り＋一時テーブルの読み直し）になりました。同じ SQL でも、DB エンジンによって実行のされ方が変わります。
> **「ORDER BY RAND() をやめれば解決」ではない**ことがわかるのがポイントです。

切り替え方は 2 通りあります。

```bash
# そのリクエストだけ切り替える
./scripts/load.sh "/random/?dblab_strategy=idrange"

# サイト全体で切り替える（負荷をかけたまま実行すると、グラフがその場で変わる）
./scripts/strategy.sh rand
./scripts/strategy.sh sample    # 元に戻す
```

レスポンスヘッダー `X-DBLab-Strategy` / `X-DBLab-Queries` / `X-DBLab-Pick-Ms` で、使われた方式・クエリ数・選択にかかった時間を確認できます。

```bash
curl -sI "http://localhost:8888/random/?dblab_strategy=cached" | grep -i x-dblab
```

### 4.1 7.2 RC での変更（変更前後の比較）

7.2 RC1 の直前に入った [r65542](https://github.com/WordPress/wordpress-develop/commit/3943b1ddd1)（[#66654](https://core.trac.wordpress.org/ticket/66654)）で、`/random/` の選び方が変わりました。RC1・RC2 のどちらにも含まれます。

| | 変更前（7.2 beta まで）＝ `rand` | 変更後（7.2 RC 以降）＝ `sample` |
|---|---|---|
| SQL | `... ORDER BY RAND() LIMIT 0, 1` | `SELECT MAX(ID)` ＋ `... AND ID IN (ランダムな 50 個)` |
| 読む行数 | 候補件数（1 万件なら 1 万行） | 最大 50 行。投稿数に関係しない |
| 偏り | なし | なし（ID ごとに等確率で引くので、ID に歯抜けがあっても偏らない。idrange との違い） |
| 苦手なとき | 投稿が多いとき | 候補が ID の中でまばらなとき。3 回外れると、候補を全件読んでプールを作る |

変更後のコアは、MAX(ID) とプールを**オブジェクトキャッシュに 10 分**置きます。このラボには永続オブジェクトキャッシュ（Redis など）がないので、どちらもリクエストごとに作り直されます。プールに落ちたときの重さがそのまま見えるのは、このためです。

`sample` を選ぶと、mu-plugin は何もせず、**コア本体の実装がそのまま動きます**（コアが 7.2 beta のときは、mu-plugin の再現版で動きます）。比べ方は次のとおりです。

```bash
./scripts/strategy.sh rand      # 変更前
./scripts/strategy.sh sample    # 変更後
./scripts/compare.sh            # latest → rand → sample → offset → idrange → cached を順番に流す
./scripts/explain.sh            # rand と sample の SQL を 1 回ずつ実行して、読んだ行数と EXPLAIN ANALYZE を比べる
```

**ID の密度を下げると、変更後の弱点が見えます。** 実際のサイトでは、投稿 ID の多くがリビジョンや添付ファイルに使われています。`./scripts/seed.sh 10000 2 20` で各記事の直後にリビジョンを 20 件ずつ作ると、ID の密度（1〜MAX(ID) のうち公開済み投稿が占める割合）が約 5% になります。この状態で測った値です（MySQL 8.4、各 200 リクエスト）。

| | `/random/`（密度 4.5%） | `/random/category/lab-a/`（密度 0.9%） |
|---|---|---|
| `rand`（変更前） | 毎回 10,001 行 | 毎回 4,001 行＋一時テーブル |
| `sample`（変更後） | 主キー検索 平均 1.1 回（約 50 行）。プール作成なし | 主キー検索 平均 2.1 回（約 100 行）。**28% のリクエストでプール作成**（5,000 行＋一時テーブル） |

小さいカテゴリーほど「3 回外れる」確率が上がり、そのたびに変更前と同じくらい読みます。**永続オブジェクトキャッシュがあるかどうかで重さが変わる**のが、変更後の特徴です。

### 単体エンドポイント（7.2 未満でも可）

| URL | 内容 |
|---|---|
| `/?dblab=none` | WordPress の起動だけ（基準線） |
| `/?dblab=latest` | 最新記事 1 件（普通のインデックス検索） |
| `/?dblab=sample` `/?dblab=rand` `/?dblab=offset` `/?dblab=idrange` `/?dblab=cached` | 各方式（sample はコアと同じ考え方を mu-plugin で再現したもの） |
| 上記に `&go=1` を付ける | 選んだ記事へ 302 リダイレクト |

## 5. スクリプト一覧

| コマンド | 内容 |
|---|---|
| `./scripts/setup.sh [件数]` | 初回セットアップ |
| `./scripts/seed.sh 50000 [本文KB] [リビジョン数]` | 学習用投稿を指定件数に作り直す。リビジョン数（1 記事あたり、最大 99）を指定すると ID の密度が下がる |
| `./scripts/load.sh [パス] [req/秒] [時間]` | k6 で負荷をかける（リダイレクトは追わない） |
| `./scripts/compare.sh [req/秒] [時間] [休憩秒]` | latest → rand → sample → offset → idrange → cached を順番に流す |
| `./scripts/strategy.sh sample\|rand\|offset\|idrange\|cached` | `/random/` の方式をサイト全体で切り替える |
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
| 一時テーブルとフルスキャン | MariaDB では `ORDER BY RAND()` が毎回一時テーブルを作る。MySQL 8.4 では `/random/` だと作らないが、カテゴリー別（GROUP BY が付く）では rand も sample のプール作成も一時テーブルを作る |
| クエリ別：1回の実行で調べた行数 | performance_schema のダイジェスト別。犯人のクエリが一目でわかる |
| クエリ別：合計実行時間 | DB が 1 秒のうち何秒をそのクエリに使っているか |

## 7. ミートアップでの進行例（20〜30 分）

1. **問い**：「`/random/` を連打したら重くなる？」と問いかけ、`/random/` の仕組みを説明します（7.2 beta までのコアは `WP::handle_random()` で `ORDER BY RAND() LIMIT 0, 1` を実行していた）
2. **普通のアクセス**：`./scripts/load.sh "/?dblab=latest"` で、1 クエリあたり数行しか読まない状態を見せます
3. **変更前の `/random/`**：`./scripts/strategy.sh rand` → `./scripts/load.sh /random/ 20 60s` を実行し、読んだ行数が桁違いに増える様子を見せます
4. **件数を増やす**：`./scripts/seed.sh 50000` → 同じ負荷をかけ、件数に比例して重くなることを見せます
5. **流量を上げる**：`./scripts/load.sh /random/ 80 60s` を実行し、同時実行中のクエリと応答時間が伸びる様子を見せます
6. **仕組みを見る**：`./scripts/explain.sh` の EXPLAIN ANALYZE で「候補を全件読む（`rows=10001`）→ 乱数で並べ替えて 1 件」を確認します
7. **自分で直してみる**：負荷をかけたまま `./scripts/strategy.sh offset` → `idrange` → `cached` と切り替え、グラフの変化を見ます
   - offset で下がらない（むしろ増える）のは「なぜ？」という問いにつながります
   - idrange は速いけれど、ID に歯抜けがあると偏ります
8. **コアの答え（7.2 RC）**：負荷をかけたまま `./scripts/strategy.sh sample` に切り替え、読んだ行数が投稿数に関係なく下がることを見せます
   - idrange の「速いけれど偏る」を、ID を 50 個引いて当たりから選ぶ（棄却サンプリング）ことで解決しています
9. **変更後の弱点**：`./scripts/seed.sh 10000 2 20` でリビジョンを増やし、`./scripts/load.sh "/random/category/lab-a/" 20 60s` を実行します。4 回に 1 回ほどプール作成（`ORDER BY RAND() LIMIT 1000`）に落ちる様子が「クエリ別」パネルに出ます
   - 永続オブジェクトキャッシュがあれば、プール作成は 10 分に 1 回で済みます
10. **まとめ**：
   - 7.2 RC 以降のコアは、投稿数が増えても `/random/` が重くならない
   - ただし小さいアーカイブやリビジョンの多いサイトではプール作成に落ちるので、永続オブジェクトキャッシュがあると安心
   - 不要なら mu-plugin で `add_filter( 'wp_enable_random_content_redirect', '__return_false' );` を入れる
   - 残すなら `/random/` へのレート制限や robots.txt を検討する
   - コアは 302＋`Cache-Control: no-cache`＋`X-Robots-Tag: noindex` を返すため、CDN でのキャッシュは効かない

ブラウザで `/random/` を開くと、Query Monitor でリクエスト内の SQL も見られます。リダイレクト前のページなので、`/?dblab=rand` や `/?dblab=sample` のほうが見やすいです。

## 8. 困ったとき

- **ポートが使われている**：`.env` ファイルを作り、`WP_PORT=8080` / `GRAFANA_PORT=3001` / `PROM_PORT=9091` / `DB_PORT=3308` のように指定します。compose とスクリプトの両方がこの値を読みます（初回セットアップ前に作ってください。WP_PORT はサイト URL にも使われます）
- **Grafana に何も出ない**：http://localhost:9090/targets で `mysql` が UP か確認します。DOWN なら `docker compose logs exporter` を見てください
- **最初から作り直す**：`docker compose down -v` → `./scripts/setup.sh`
- **seed の「ID の密度」が思ったより低い**：seed し直すと、前回の記事が使っていた ID が空いたまま残り、密度が下がります（idrange が偏り、sample は外れやすくなる）。決まった密度で比べたいときは「最初から作り直す」を行ってから seed してください
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
wordpress/mu-plugins/db-load-lab.php 方式の切り替え・単体エンドポイント（local 環境でしか動かない）。読み込むだけ
wordpress/mu-plugins/db-load-lab/    strategies.php（各方式）・random-redirect.php（/random/ の差し替え）・endpoint.php（/?dblab=）
scripts/php/seed.php                 投稿の一括作成
k6/load.js                           負荷シナリオ
scripts/*.sh                         操作用スクリプト
```

PMM（Percona Monitoring and Management）を使う方法もあります。
今回は「何を測っているか」が追いやすいよう、Prometheus と Grafana で必要なパネルだけに絞りました。
