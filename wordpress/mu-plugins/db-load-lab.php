<?php
/**
 * Plugin Name: DB Load Lab
 * Description: ミートアップ学習用。ランダムに1件選ぶ方式ごとの DB 負荷を比較するための実験プラグイン。本番サイトには入れないでください。
 * Version:     1.1.0
 *
 * 1) WordPress 7.2 の /random/ の「選び方」を差し替える
 *      /random/?dblab_strategy=rand            … そのリクエストだけ差し替え
 *      wp option update dblab_strategy rand    … サイト全体で差し替え（負荷をかけたまま切り替えられる）
 *
 * 2) 7.2 未満でも試せる単体エンドポイント
 *      /?dblab=sample | rand | offset | idrange | cached | latest | none
 *      &go=1 を付けると選んだ記事へ 302 リダイレクト
 *
 * 方式:
 *   sample  … 7.2 RC 以降のコア。ランダムな ID を 50 個選んで主キーで引き、当たったものから選ぶ。
 *             3 回外れたら ORDER BY RAND() LIMIT 1000 の「プール」から選ぶ
 *   rand    … 7.2 beta までのコア。ORDER BY RAND() LIMIT 1。候補を全件読んで乱数を振り、並べ替える
 *   offset  … COUNT(*) で件数を数え、ランダムな OFFSET で1件取る。OFFSET 分は読み飛ばす
 *   idrange … MIN〜MAX の ID から乱数を選び ID >= x の最初の1件。ほぼ一瞬だが ID の歯抜けがあると偏る
 *   cached  … 候補 ID 一覧を transient に 5 分キャッシュし PHP で選ぶ。DB は軽いが一覧の鮮度と容量がトレードオフ
 *
 * @package DB_Load_Lab
 */

defined( 'ABSPATH' ) || exit;

// 事故防止：local / development 環境以外では何もしない。
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	return;
}

require_once __DIR__ . '/db-load-lab/strategies.php';
require_once __DIR__ . '/db-load-lab/random-redirect.php';
require_once __DIR__ . '/db-load-lab/endpoint.php';
