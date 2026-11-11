#!/usr/bin/env bash
# ターミナルで 1 秒ごとの増分を見る（Grafana を使わない手軽版）。Ctrl+C で終了
#   mysqladmin -r は「前回との差分」を出す。1 回目（起動からの累計）は読み飛ばし、2 回目から 1 秒 1 行で表示する
#   Threads_running は現在値なので差分にすると意味がなくなるため、ここでは出さない
source "$(dirname "$0")/_common.sh"
docker compose exec -T db mysqladmin -uroot -proot extended-status -r -i1 2>/dev/null \
  | awk -F'|' '
    BEGIN { split("Questions Innodb_rows_read Handler_read_key Handler_read_next Handler_read_rnd_next Created_tmp_tables Select_scan", cols, " ") }
    {
      name = $2; gsub(/ /, "", name); v = $3; gsub(/ /, "", v)
      val[name] = v
      if (name != "Uptime") next          # Uptime はブロックの終わり近くに必ず出るので、ここで 1 行出力する
      if (block++ == 0) next              # 1 回目は累計なので捨てる
      # 見出しは全角文字の表示幅がずれないよう、空白込みの固定文字列にしている
      if ((block - 2) % 20 == 0) print " 経過秒  Questions    rows_read  read_key   read_next read_rnd_next tmp表 全走査"
      # 時刻は出さない（パイプのバッファで 2 秒分がまとめて届くと同じ時刻が並ぶため）。1 ブロック = 1 秒
      printf "%7s %10s %12s %9s %11s %13s %5s %6s\n", block - 1, val[cols[1]], val[cols[2]], val[cols[3]], val[cols[4]], val[cols[5]], val[cols[6]], val[cols[7]]
      fflush()
    }'
