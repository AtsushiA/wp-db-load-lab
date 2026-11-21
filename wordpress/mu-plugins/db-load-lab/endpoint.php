<?php
/**
 * 単体エンドポイント /?dblab=MODE（7.2 未満でも試せる）。
 *
 * @package DB_Load_Lab
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'dblab_handle_endpoint', 99 );

/**
 * /?dblab=MODE のリクエストを処理する。&go=1 なら選んだ記事へ 302 リダイレクトする。
 *
 * @return void
 */
function dblab_handle_endpoint(): void {
	global $wpdb;

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 状態を変えない観察用のエンドポイントなので nonce は不要。
	if ( ! isset( $_GET['dblab'] ) ) {
		return;
	}
	$mode    = sanitize_key( wp_unslash( $_GET['dblab'] ) );
	$go      = ! empty( $_GET['go'] );
	$started = microtime( true );
	// phpcs:enable

	// 条件の順番は 7.2 コアの /random/ と同じ（sample 方式がパスワード条件を外すときに位置を使う）。
	$base = "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} WHERE 1=1 AND {$wpdb->posts}.post_type = 'post' AND {$wpdb->posts}.post_status = 'publish' AND {$wpdb->posts}.post_password = ''";

	// モードごとに 1 件選ぶ。
	if ( 'none' === $mode ) {
		// WordPress の起動だけのコスト（比較の基準線）。
		$post_id = 0;
	} elseif ( 'latest' === $mode ) {
		// 普通の「最新記事 1 件」。インデックスで即座に取れる。
		$post_id = (int) $wpdb->get_var( "{$base} ORDER BY {$wpdb->posts}.post_date DESC LIMIT 0, 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	} elseif ( in_array( $mode, DBLAB_STRATEGIES, true ) ) {
		$post_id = dblab_pick_id( $mode, $base );
	} else {
		status_header( 400 );
		echo 'unknown mode. use: none, latest, ' . esc_html( implode( ', ', DBLAB_STRATEGIES ) );
		exit;
	}
	$pick_ms = ( microtime( true ) - $started ) * 1000;

	nocache_headers();
	dblab_send_debug_headers( $mode, $pick_ms );

	if ( $post_id && $go ) {
		wp_safe_redirect( get_permalink( $post_id ), 302 );
		exit;
	}

	header( 'Content-Type: text/plain; charset=utf-8' );
	printf(
		"mode=%s id=%d queries=%d pick_ms=%.2f\n",
		esc_html( $mode ),
		(int) $post_id,
		(int) get_num_queries(),
		(float) $pick_ms
	);
	exit;
}
