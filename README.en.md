# WordPress DB Load Lab

English | [日本語](README.md)

A local, meetup-friendly environment that visualizes in real time how WordPress page requests load the database.
The case study is `/random/` in WordPress 7.2, which redirects to a random post.
WordPress 7.2 RC changed how `/random/` picks the post, from `ORDER BY RAND()` to looking up random IDs by primary key, so **you can compare the old and new methods under the same conditions** (see [4.1 The change in 7.2 RC](#41-the-change-in-72-rc-before-and-after)).

```
 k6 (load) ──▶ WordPress ──▶ MySQL 8.4 ◀── mysqld_exporter ◀── Prometheus ◀── Grafana
                  └ the "DB Load Lab" mu-plugin switches how the random post is picked
```

| URL | What it is |
|---|---|
| http://localhost:8888 | WordPress (admin / admin) |
| http://localhost:3000 | Grafana (no login; dashboard "WordPress DB 負荷ラボ") |
| http://localhost:9090 | Prometheus |

> ⚠ For local learning only. The passwords are hard-coded, so never run this on a public server.

The dashboard, script output, and sample content are in Japanese. The panel names are translated in [6. Reading the dashboard](#6-reading-the-dashboard).

---

## 1. Requirements

- Docker Desktop (or Docker Engine + Compose v2). Allocating 4 GB of memory or more is recommended
- macOS, Linux, or Windows (WSL2). Apple Silicon works
- Free ports: 8888, 3000, 9090, 3307 (to change them, see [8. Troubleshooting](#8-troubleshooting))

This lab doesn't use wp-env, which manages its own containers and makes it hard to put the monitoring stack on the same network.
WordPress, MySQL, and monitoring all live in the single `compose.yaml`.

## 2. Setup (about 5 minutes the first time)

```bash
cd wp-db-load-lab
./scripts/setup.sh          # Prepares 10,000 posts. For a different count: ./scripts/setup.sh 50000
```

What setup.sh does:

1. Pulls the images and starts the containers
2. Installs WordPress
3. **Updates WordPress to nightly (7.2 development build, currently RC2 or later)** so that `/random/` is available
4. Sets permalinks to `/%postname%/`
5. Installs Query Monitor
6. Creates the sample posts in bulk with SQL (a few seconds for 10,000 posts)

After 7.2 is officially released, you can use the latest stable version instead:

```bash
WP_CORE_VERSION=latest ./scripts/setup.sh
```

You're ready when the last lines include "✔ /random/ が使えます" (/random/ is available). The text in parentheses tells you which method core uses: ID sampling (RC and later) or `ORDER BY RAND()` (beta and earlier).
Even on a version earlier than 7.2, you can run the same comparison through standalone endpoints such as `/?dblab=sample` and `/?dblab=rand`.

## 3. Try it

1. Open Grafana (http://localhost:3000). It refreshes every 2 seconds
2. Generate load from another terminal

```bash
./scripts/load.sh "/random/?dblab_strategy=rand"      # The old method (7.2 beta). 20 req/s for 30 s
./scripts/load.sh "/random/?dblab_strategy=sample"    # The new method (7.2 RC)
./scripts/load.sh /random/ 50 60s                     # The site-wide setting (sample by default), 50 req/s for 60 s
```

With rand, "読んだ行数 / 秒" (rows read per second) on the dashboard jumps, and the `ORDER BY RAND()` query rises to the top of "クエリ別：1回の実行で調べた行数" (rows examined per execution, by query). Switch to sample and it drops by orders of magnitude.

## 4. Five ways to pick a post

The bundled mu-plugin (`wordpress/mu-plugins/db-load-lab.php`) replaces how `/random/` picks its one post.
It keeps the WHERE clause (post type, category, author) that core builds, so `/random/category/lab-a/` is compared under the same conditions.

| Strategy | How it works | Rows read per pick | Weakness |
|---|---|---|---|
| `sample` (7.2 RC and later core = **after**; default) | Pick 50 random IDs between 1 and MAX(ID), look them up by primary key, and choose one of the hits. After 3 misses, choose from a pool selected with `ORDER BY RAND() LIMIT 1000` | Up to 50 rows per attempt | When candidates are sparse among the IDs (many revisions, a small category, and so on), it falls back to building the pool, which reads every candidate |
| `rand` (7.2 beta and earlier core = **before**) | `ORDER BY RAND() LIMIT 0, 1` | Number of candidates (about twice that on MariaDB, through a temporary table) | Cost grows with the number of posts |
| `offset` | `COUNT(*)` → `LIMIT random, 1` | Number of candidates + half of them on average | No sorting, but on MySQL 8.4 it reads more rows than rand |
| `idrange` | Pick a random ID between MIN and MAX, then take the first post with `ID >= x` | About 1 | Gaps in IDs make some posts more likely to be picked |
| `cached` | Cache the candidate IDs in a transient for 5 minutes and pick in PHP | Number of candidates, only when the cache expires | The list can be stale, and with many posts the option row gets large |

> Measured with one run each against 10,000 posts on MySQL 8.4 (reproduce with `./scripts/explain.sh`).
> rand reads all 10,001 candidate rows through the index and keeps only the row with the smallest random value (no temporary table). sample reads 1 row for MAX(ID) and at most 50 rows for the primary key lookup of 50 IDs. offset reads 10,001 rows for COUNT and 5,000 on average for LIMIT, so **it reads more than rand**. idrange reads 1 row.
> On MariaDB 10.11, rand writes every candidate to a temporary table and then sorts it, which came to about 50,000 rows for 25,000 posts (the reads plus re-reading the temporary table). The same SQL can run differently depending on the database engine.
> The key takeaway: **just dropping ORDER BY RAND() doesn't solve the problem.**

You can switch strategies in two ways.

```bash
# Switch for these requests only
./scripts/load.sh "/random/?dblab_strategy=idrange"

# Switch site-wide (run it while load is running and the graphs change on the spot)
./scripts/strategy.sh rand
./scripts/strategy.sh sample    # Switch back
```

The response headers `X-DBLab-Strategy` / `X-DBLab-Queries` / `X-DBLab-Pick-Ms` show the strategy used, the number of queries, and the time spent picking.

```bash
curl -sI "http://localhost:8888/random/?dblab_strategy=cached" | grep -i x-dblab
```

### 4.1 The change in 7.2 RC (before and after)

[r65542](https://github.com/WordPress/wordpress-develop/commit/3943b1ddd1) ([#66654](https://core.trac.wordpress.org/ticket/66654)), committed just before 7.2 RC1, changed how `/random/` picks the post. It's included in both RC1 and RC2.

| | Before (7.2 beta and earlier) = `rand` | After (7.2 RC and later) = `sample` |
|---|---|---|
| SQL | `... ORDER BY RAND() LIMIT 0, 1` | `SELECT MAX(ID)` + `... AND ID IN (50 random IDs)` |
| Rows read | Number of candidates (10,000 rows for 10,000 posts) | At most 50 rows, regardless of the number of posts |
| Bias | None | None (every ID is equally likely to be drawn, so gaps between IDs don't bias the pick, unlike idrange) |
| Weak spot | Many posts | Candidates that are sparse among the IDs. After 3 misses, it reads every candidate to build a pool |

The new core keeps MAX(ID) and the pool **in the object cache for 10 minutes**. This lab has no persistent object cache (such as Redis), so both are rebuilt on every request. That's why the cost of falling back to the pool shows up as is.

With `sample`, the mu-plugin does nothing and **core's own implementation runs** (on a 7.2 beta core, the mu-plugin's re-implementation runs instead). To compare:

```bash
./scripts/strategy.sh rand      # Before
./scripts/strategy.sh sample    # After
./scripts/compare.sh            # Runs latest → rand → sample → offset → idrange → cached in turn
./scripts/explain.sh            # Runs the rand and sample SQL once each and compares rows read and EXPLAIN ANALYZE
```

**Lower the ID density to see the new method's weak spot.** On real sites, many post IDs belong to revisions and attachments. `./scripts/seed.sh 10000 2 20` creates 20 revisions right after each post, which brings the ID density (the share of IDs between 1 and MAX(ID) that are published posts) down to about 5%. Measured in that state (MySQL 8.4, 200 requests each):

| | `/random/` (density 4.5%) | `/random/category/lab-a/` (density 0.9%) |
|---|---|---|
| `rand` (before) | 10,001 rows every time | 4,001 rows + a temporary table every time |
| `sample` (after) | 1.1 primary key lookups on average (about 50 rows). No pool | 2.1 primary key lookups on average (about 100 rows). **28% of requests build the pool** (5,000 rows + a temporary table) |

The smaller the category, the more likely three misses in a row become, and each time it reads about as much as the old method. **Whether there's a persistent object cache changes the cost** — that's the defining trait of the new method.

### Standalone endpoints (work before 7.2)

| URL | What it does |
|---|---|
| `/?dblab=none` | Only boots WordPress (baseline) |
| `/?dblab=latest` | Latest post (a normal index lookup) |
| `/?dblab=sample` `/?dblab=rand` `/?dblab=offset` `/?dblab=idrange` `/?dblab=cached` | Each strategy (sample re-implements core's approach in the mu-plugin) |
| Add `&go=1` to any of the above | 302 redirect to the picked post |

## 5. Scripts

| Command | What it does |
|---|---|
| `./scripts/setup.sh [count]` | First-time setup |
| `./scripts/seed.sh 50000 [body KB] [revisions]` | Recreates the sample posts at the given count. Specifying revisions (per post, up to 99) lowers the ID density |
| `./scripts/load.sh [path] [req/s] [duration]` | Generates load with k6 (does not follow redirects) |
| `./scripts/compare.sh [req/s] [duration] [rest s]` | Runs latest → rand → sample → offset → idrange → cached in turn |
| `./scripts/strategy.sh sample\|rand\|offset\|idrange\|cached` | Switches the `/random/` strategy site-wide |
| `./scripts/explain.sh` | Runs each strategy's SQL once, compares rows read, and shows EXPLAIN ANALYZE |
| `./scripts/watch.sh` | Streams per-second counter deltas in the terminal, without Grafana |
| `./scripts/reset-stats.sh` | Resets per-query statistics, counters, and transients |

**Load is fixed in requests per second, not in VUs** (k6's constant-arrival-rate).
Without a fixed request rate, faster strategies serve more requests, their database numbers look bigger, and the comparison breaks.
That's a good meetup talking point too.

## 6. Reading the dashboard

| Panel | What to look for |
|---|---|
| クエリ数 / 秒 (queries per second) | About the same for every strategy (query count alone doesn't tell you how heavy a query is) |
| 読んだ行数 / 秒 (rows read per second) | An order-of-magnitude jump here signals a heavy query (`Innodb_rows_read`) |
| 1クエリあたり読んだ行数 (rows read per query) | A few to a few dozen for a normal page. Watch out once it passes a few thousand |
| 同時実行中のクエリ (running queries) | Rises when the database starts to choke. As you raise the request rate, rand climbs first |
| Handler：行の読み方の内訳 (how rows are read) | `read_key` = single index lookup, `read_next` = reading forward in index order, `read_rnd_next` = reading a table (including temporary tables) from start to end |
| 一時テーブルとフルスキャン (temporary tables and full scans) | On MariaDB, `ORDER BY RAND()` creates a temporary table every time. On MySQL 8.4, `/random/` doesn't, but on category archives (which add GROUP BY) both rand and sample's pool building create one |
| クエリ別：1回の実行で調べた行数 (rows examined per execution, by query) | Per performance_schema digest. The culprit query stands out at a glance |
| クエリ別：合計実行時間 (total execution time, by query) | How much of each second the database spends on each query |

## 7. Sample meetup flow (20–30 minutes)

1. **Question**: Ask "Does hammering `/random/` make the site slow?" and explain how `/random/` works (up to 7.2 beta, core's `WP::handle_random()` ran `ORDER BY RAND() LIMIT 0, 1`)
2. **A normal request**: Run `./scripts/load.sh "/?dblab=latest"` to show each query reading only a few rows
3. **`/random/` before the change**: Run `./scripts/strategy.sh rand` → `./scripts/load.sh /random/ 20 60s` and show rows read jumping by orders of magnitude
4. **More posts**: Run `./scripts/seed.sh 50000`, apply the same load, and show the cost growing with the number of posts
5. **More traffic**: Run `./scripts/load.sh /random/ 80 60s` and show running queries and response times climbing
6. **Look inside**: In the EXPLAIN ANALYZE output of `./scripts/explain.sh`, confirm that it reads every candidate (`rows=10001`), sorts by a random value, and keeps one
7. **Try your own fix**: While load is running, switch `./scripts/strategy.sh offset` → `idrange` → `cached` and watch the graphs change
   - offset doesn't go down (it actually goes up), which leads to the question "why?"
   - idrange is fast, but gaps in IDs bias it
8. **Core's answer (7.2 RC)**: While load is running, switch to `./scripts/strategy.sh sample` and show rows read dropping regardless of the number of posts
   - It solves idrange's "fast but biased" problem by drawing 50 IDs and choosing among the hits (rejection sampling)
9. **The new method's weak spot**: Add revisions with `./scripts/seed.sh 10000 2 20` and run `./scripts/load.sh "/random/category/lab-a/" 20 60s`. About one request in four falls back to building the pool (`ORDER BY RAND() LIMIT 1000`), which shows up in the "クエリ別" (by query) panels
   - With a persistent object cache, the pool is built only once every 10 minutes
10. **Wrap-up**:
   - From 7.2 RC, core keeps `/random/` light as the number of posts grows
   - Small archives and sites with many revisions still fall back to the pool, so a persistent object cache helps
   - If you don't need it, add `add_filter( 'wp_enable_random_content_redirect', '__return_false' );` in an mu-plugin
   - If you keep it, consider rate-limiting `/random/` or using robots.txt
   - Core returns a 302 with `Cache-Control: no-cache` and `X-Robots-Tag: noindex`, so CDN caching doesn't help

If you open `/random/` in a browser, Query Monitor shows the SQL in the request. It's the page before the redirect, so `/?dblab=rand` or `/?dblab=sample` is easier to read.

## 8. Troubleshooting

- **A port is already in use**: Create a `.env` file with values such as `WP_PORT=8080` / `GRAFANA_PORT=3001` / `PROM_PORT=9091` / `DB_PORT=3308`. Both compose and the scripts read it (create it before the first setup; `WP_PORT` is also used for the site URL)
- **Grafana shows nothing**: Check that `mysql` is UP at http://localhost:9090/targets. If it's DOWN, look at `docker compose logs exporter`
- **Start over from scratch**: `docker compose down -v` → `./scripts/setup.sh`
- **The "ID の密度" (ID density) from seed is lower than expected**: When you seed again, the IDs the previous posts used stay empty and the density drops (idrange gets biased and sample misses more often). To compare at a set density, start over from scratch before seeding
- **Shut down**: `docker compose down` (keeps the data) / `docker compose down -v` (deletes the data too)
- **Watch slow queries in a file**: `docker compose exec db tail -f /var/lib/mysql/slow.log` (logs queries taking 0.05 s or longer)
- **Everything is slow on Docker Desktop for Mac**: Allocate more CPU and memory in Docker Desktop's settings. Absolute numbers vary by environment, but the differences between strategies still show up

## 9. Files

```
compose.yaml                         All services
mysql/conf.d/lab.cnf                 performance_schema and slow query log
mysql/init/01-exporter.sql           Monitoring user
prometheus/prometheus.yml            Scrapes every 2 seconds
grafana/provisioning/                Auto-registers the data source and dashboard
grafana/dashboards/wp-db-load.json   Dashboard
wordpress/mu-plugins/db-load-lab.php Strategy switching and standalone endpoints (runs only in local environments). Loader only
wordpress/mu-plugins/db-load-lab/    strategies.php (each strategy), random-redirect.php (overrides /random/), endpoint.php (/?dblab=)
scripts/php/seed.php                 Bulk post creation
k6/load.js                           Load scenario
scripts/*.sh                         Helper scripts
```

You could also use PMM (Percona Monitoring and Management).
This lab sticks to Prometheus and Grafana with only the panels it needs, so it's easy to follow what's being measured.
