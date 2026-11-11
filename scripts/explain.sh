#!/usr/bin/env bash
# 各方式の SQL を 1 回ずつ実行して「何行読んだか」を比べる＋ EXPLAIN ANALYZE
#   ./scripts/explain.sh
source "$(dirname "$0")/_common.sh"

W="wp_posts.post_password = '' AND wp_posts.post_type = 'post' AND wp_posts.post_status = 'publish'"
COUNT=$(mysql_root -N -e "SELECT COUNT(*) FROM wp_posts WHERE $W")
OFFSET=$(( RANDOM % (COUNT > 0 ? COUNT : 1) ))
# mu-plugin の idrange と同じく、テーブル全体の MIN〜MAX(ID) から起点を選ぶ
read -r MINID MAXID <<<"$(mysql_root -N -e "SELECT MIN(ID), MAX(ID) FROM wp_posts")"
PIVOT=$(( MINID + (RANDOM * 32768 + RANDOM) % (MAXID - MINID + 1) ))

declare -a NAMES SQLS
NAMES+=("rand    : ORDER BY RAND()（7.2 コア）"); SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W ORDER BY RAND() LIMIT 0, 1")
NAMES+=("offset 1: COUNT(*)");                    SQLS+=("SELECT COUNT(*) FROM wp_posts WHERE 1=1 AND $W")
NAMES+=("offset 2: LIMIT ${OFFSET}, 1");           SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W LIMIT ${OFFSET}, 1")
NAMES+=("idrange : ID >= ${PIVOT}");               SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W AND wp_posts.ID >= ${PIVOT} ORDER BY wp_posts.ID LIMIT 1")
NAMES+=("latest  : 最新 1 件（参考）");            SQLS+=("SELECT wp_posts.ID FROM wp_posts WHERE 1=1 AND $W ORDER BY wp_posts.post_date DESC LIMIT 0, 1")

# Handler_* / Created_tmp_tables はセッション単位で FLUSH STATUS でリセットできる。
# Innodb_rows_read はグローバル専用（セッション値がない）ので、実行前後の差分を取る。
# 負荷をかけている最中に実行すると rows_read に他のリクエストの分が混ざる点に注意
G="(SELECT VARIABLE_VALUE FROM performance_schema.global_status WHERE VARIABLE_NAME='Innodb_rows_read')"
S() { echo "(SELECT VARIABLE_VALUE FROM performance_schema.session_status WHERE VARIABLE_NAME='$1')"; }

echo "候補: ${COUNT} 件"
printf "\n%10s %9s %10s %10s %14s %5s  %s\n" "rows_read" "read_key" "read_next" "read_prev" "read_rnd_next" "tmp表" "方式"
for i in "${!SQLS[@]}"; do
  # SHOW SESSION STATUS 自体はカウンタを増やさないので、先に Handler_* を取り、最後に rows_read の差分を取る
  out=$(mysql_root -N -e "SET @rr = ${G}; FLUSH STATUS; ${SQLS[$i]};
    SHOW SESSION STATUS WHERE Variable_name IN ('Created_tmp_tables','Handler_read_key','Handler_read_next','Handler_read_prev','Handler_read_rnd_next');
    SELECT ${G} - @rr;" | tail -6 | awk '{print $NF}' | tr '\n' ' ')
  read -r tmp key next prev rnext rows <<<"$out"   # 名前順: Created_tmp_tables, Handler_read_key, _next, _prev, _rnd_next、最後に rows_read
  printf "%10s %9s %10s %10s %14s %5s  %s\n" "$rows" "$key" "$next" "$prev" "$rnext" "$tmp" "${NAMES[$i]}"
done

echo; echo "──── EXPLAIN ANALYZE（rand）"
mysql_root -e "EXPLAIN ANALYZE ${SQLS[0]}\G" | sed -n '/EXPLAIN:/,$p'
echo; echo "──── EXPLAIN ANALYZE（idrange）"
mysql_root -e "EXPLAIN ANALYZE ${SQLS[3]}\G" | sed -n '/EXPLAIN:/,$p'
