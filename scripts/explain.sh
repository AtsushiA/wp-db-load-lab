#!/usr/bin/env bash
# 各方式の SQL を 1 回ずつ実行して「何行読んだか」を比べる＋ EXPLAIN ANALYZE
#   ./scripts/explain.sh
source "$(dirname "$0")/_common.sh"

# 条件の順番は 7.2 コアの /random/ と同じ。TS はパスワード条件を外したもの（sample のプール用）
TS="wp_posts.post_type = 'post' AND wp_posts.post_status = 'publish'"
W="$TS AND wp_posts.post_password = ''"
COUNT=$(mysql_root -N -e "SELECT COUNT(*) FROM wp_posts WHERE $W")
OFFSET=$(( RANDOM % (COUNT > 0 ? COUNT : 1) ))
# mu-plugin の idrange と同じく、テーブル全体の MIN〜MAX(ID) から起点を選ぶ
read -r MINID MAXID <<<"$(mysql_root -N -e "SELECT MIN(ID), MAX(ID) FROM wp_posts")"
PIVOT=$(( MINID + (RANDOM * 32768 + RANDOM) % (MAXID - MINID + 1) ))
# 7.2 RC のコアと同じく、1〜MAX(ID) から重複しない ID を 50 個選ぶ
SAMPLE_IDS=$(awk -v max="$MAXID" 'BEGIN { srand(); n = (max < 50 ? max : 50); while (c < n) { x = int(rand() * max) + 1; if (!(x in s)) { s[x] = 1; c++ } } for (k in s) printf "%s%s", (i++ ? "," : ""), k }')
HITS=$(mysql_root -N -e "SELECT COUNT(*) FROM wp_posts WHERE $W AND wp_posts.ID IN (${SAMPLE_IDS})")

declare -a NAMES SQLS
NAMES+=("rand    : ORDER BY RAND()（変更前: 7.2 beta のコア）");  SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W ORDER BY RAND() LIMIT 0, 1"); I_RAND=$(( ${#SQLS[@]} - 1 ))
NAMES+=("sample 1: MAX(ID)（変更後: 7.2 RC のコア）");          SQLS+=("SELECT MAX(ID) FROM wp_posts")
NAMES+=("sample 2: ID IN (ランダムな 50 個) → 当たり ${HITS} 件"); SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W AND wp_posts.ID IN (${SAMPLE_IDS})"); I_SAMPLE=$(( ${#SQLS[@]} - 1 ))
NAMES+=("sample 3: プール（3 回外れたときだけ）");              SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $TS ORDER BY RAND() LIMIT 1000")
NAMES+=("offset 1: COUNT(*)");                                  SQLS+=("SELECT COUNT(*) FROM wp_posts WHERE 1=1 AND $W")
NAMES+=("offset 2: LIMIT ${OFFSET}, 1");                         SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W LIMIT ${OFFSET}, 1")
NAMES+=("idrange : ID >= ${PIVOT}");                             SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W AND wp_posts.ID >= ${PIVOT} ORDER BY wp_posts.ID LIMIT 1"); I_IDRANGE=$(( ${#SQLS[@]} - 1 ))
NAMES+=("latest  : 最新 1 件（参考）");                          SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W ORDER BY wp_posts.post_date DESC LIMIT 0, 1")

# Handler_* / Created_tmp_tables はセッション単位で FLUSH STATUS でリセットできる。
# Innodb_rows_read はグローバル専用（セッション値がない）ので、実行前後の差分を取る。
# 負荷をかけている最中に実行すると rows_read に他のリクエストの分が混ざる点に注意
G="(SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_rows_read')"
S() { echo "(SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME='$1')"; }

echo "候補: ${COUNT} 件、MAX(ID): ${MAXID}（ID の密度 $(( COUNT * 100 / (MAXID > 0 ? MAXID : 1) ))%）"
# 時間はサーバー側で測った SQL 1 文の実行時間（performance_schema の文履歴の TIMER_WAIT）。
# 履歴は新しい順に「rows_read の差分 → SHOW SESSION STATUS → 測りたい SQL」と並ぶので OFFSET 2 で取る。
# 測りたい SQL と時間を取る SELECT の間に文を足したら、OFFSET も合わせて変えること
T="(SELECT ROUND(TIMER_WAIT / 1e9, 3) FROM performance_schema.events_statements_history WHERE THREAD_ID = PS_CURRENT_THREAD_ID() ORDER BY EVENT_ID DESC LIMIT 1 OFFSET 2)"
# 全角を含む見出しは printf の幅指定だと表示幅がずれるので、空白込みの固定文字列にしている
printf "\n%10s %9s %10s %10s %14s %5s %s  %s\n" "rows_read" "read_key" "read_next" "read_prev" "read_rnd_next" "tmp表" " 時間(ms)" "方式"
for i in "${!SQLS[@]}"; do
  # SHOW SESSION STATUS 自体はカウンタを増やさないので、先に Handler_* を取り、そのあと rows_read の差分と時間を取る
  out=$(mysql_root -N -e "SET @rr = ${G}; FLUSH STATUS; ${SQLS[$i]};
    SHOW SESSION STATUS WHERE Variable_name IN ('Created_tmp_tables','Handler_read_key','Handler_read_next','Handler_read_prev','Handler_read_rnd_next');
    SELECT ${G} - @rr;
    SELECT ${T};" | tail -7 | awk '{print $NF}' | tr '\n' ' ')
  read -r tmp key next prev rnext rows ms <<<"$out"   # 名前順: Created_tmp_tables, Handler_read_key, _next, _prev, _rnd_next、続いて rows_read と時間
  printf "%10s %9s %10s %10s %14s %5s %9s  %s\n" "$rows" "$key" "$next" "$prev" "$rnext" "$tmp" "$ms" "${NAMES[$i]}"
done

echo; echo "──── EXPLAIN ANALYZE（rand：変更前）"
mysql_root -e "EXPLAIN ANALYZE ${SQLS[$I_RAND]}\G" | sed -n '/EXPLAIN:/,$p'
echo; echo "──── EXPLAIN ANALYZE（sample 2：変更後）"
mysql_root -e "EXPLAIN ANALYZE ${SQLS[$I_SAMPLE]}\G" | sed -n '/EXPLAIN:/,$p' | sed -E 's/ID in \([0-9,]+\)/ID in (ランダムな 50 個)/'
echo; echo "──── EXPLAIN ANALYZE（idrange）"
mysql_root -e "EXPLAIN ANALYZE ${SQLS[$I_IDRANGE]}\G" | sed -n '/EXPLAIN:/,$p'
