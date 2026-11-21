<?php
/**
 * ランダムに 1 件選ぶ各方式の実装。
 *
 * @package DB_Load_Lab
 */

defined( 'ABSPATH' ) || exit;

/**
 * 選べる方式。
 * sample が 7.2 RC 以降のコア、rand が 7.2 beta までのコアと同じ選び方。
 */
const DBLAB_STRATEGIES = array( 'sample', 'rand', 'offset', 'idrange', 'cached' );

/**
 * 方式の指定がないときに使う方式（現在のコアと同じ選び方）。
 */
const DBLAB_DEFAULT_STRATEGY = 'sample';

/*
 * sample 方式の定数。7.2 RC のコア（wp_get_random_content_sample_ids() と
 * wp_random_content_posts_pre_query()）と値をそろえている。片方だけ変えると比較にならない。
 */
const DBLAB_SAMPLE_SIZE     = 50;
const DBLAB_SAMPLE_ATTEMPTS = 3;
const DBLAB_POOL_SIZE       = 1000;

/**
 * 今回のリクエストで使う方式を返す。
 *
 * クエリ文字列 dblab_strategy があればそれを、なければ option dblab_strategy を使う。
 *
 * @return string DBLAB_STRATEGIES のいずれか。
 */
function dblab_strategy(): string {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 状態を変えない表示用の切り替えなので nonce は不要。
	$strategy = isset( $_GET['dblab_strategy'] ) ? sanitize_key( wp_unslash( $_GET['dblab_strategy'] ) ) : get_option( 'dblab_strategy', DBLAB_DEFAULT_STRATEGY );
	return in_array( $strategy, DBLAB_STRATEGIES, true ) ? $strategy : DBLAB_DEFAULT_STRATEGY;
}

/**
 * 「ORDER BY / LIMIT を除いた、候補 ID を返す SELECT 文」から 1 件の ID を選ぶ。
 *
 * @param string $strategy 方式。
 * @param string $base_sql 例: SELECT wp_posts.ID FROM wp_posts WHERE ... （GROUP BY 付きでも可）。
 * @return int 選んだ投稿 ID。0 なら候補なし。
 */
function dblab_pick_id( string $strategy, string $base_sql ): int {
	global $wpdb;

	switch ( $strategy ) {
		case 'sample':
			return dblab_pick_by_sampling( $base_sql );

		case 'offset':
			if ( preg_match( '/\sGROUP BY\s/i', $base_sql ) ) {
				// タクソノミーアーカイブは GROUP BY が付くので、サブクエリで包んで数える。
				$count_sql = "SELECT COUNT(*) FROM ( {$base_sql} ) AS dblab_candidates";
			} else {
				$count_sql = preg_replace( '/^\s*SELECT\s.+?\sFROM\s/is', 'SELECT COUNT(*) FROM ', $base_sql, 1 );
			}
			$count = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- コアが組み立てた SQL を加工したもの。
			if ( $count < 1 ) {
				return 0;
			}
			$offset = wp_rand( 0, $count - 1 );
			return (int) $wpdb->get_var( "{$base_sql} LIMIT {$offset}, 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		case 'idrange':
			// MIN/MAX は主キーの端を見るだけなので一瞬。
			$range = $wpdb->get_row( "SELECT MIN(ID) AS min_id, MAX(ID) AS max_id FROM {$wpdb->posts}" );
			if ( ! $range || ! $range->max_id ) {
				return 0;
			}
			$pivot = wp_rand( (int) $range->min_id, (int) $range->max_id );
			$id    = (int) $wpdb->get_var( dblab_add_condition( $base_sql, "{$wpdb->posts}.ID >= {$pivot}" ) . " ORDER BY {$wpdb->posts}.ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $id ) {
				// pivot より後ろに候補がなければ先頭から。
				$id = (int) $wpdb->get_var( "{$base_sql} ORDER BY {$wpdb->posts}.ID ASC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			return $id;

		case 'cached':
			$key = 'dblab_ids_' . md5( $base_sql );
			$ids = get_transient( $key );
			if ( false === $ids ) {
				$ids = array_map( 'intval', $wpdb->get_col( $base_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				set_transient( $key, $ids, 5 * MINUTE_IN_SECONDS );
			}
			return $ids ? (int) $ids[ array_rand( $ids ) ] : 0;

		case 'rand':
		default:
			return (int) $wpdb->get_var( "{$base_sql} ORDER BY RAND() LIMIT 0, 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

/**
 * 7.2 RC のコアと同じ考え方で 1 件選ぶ（棄却サンプリング）。
 *
 * 1 〜 MAX(ID) からランダムな ID を 50 個選んで主キーで引き、候補に当たったものから 1 件選ぶ。
 * 読む行数は投稿数によらず最大 50 行。3 回続けて外れたら（候補が ID の中でまばらなら）、
 * 候補を ORDER BY RAND() LIMIT 1000 で選んだ「プール」から選ぶ。
 * 単体エンドポイントと、sample 方式を持たないコア（7.2 beta まで）で使う。
 *
 * @param string $base_sql 候補 ID を返す SELECT 文（ORDER BY / LIMIT なし）。
 * @return int 選んだ投稿 ID。0 なら候補なし。
 */
function dblab_pick_by_sampling( string $base_sql ): int {
	global $wpdb;

	// 主キーの最大値。コアと同じくオブジェクトキャッシュに 10 分置く。
	$max_id = wp_cache_get( 'max_post_id', 'dblab' );
	if ( ! is_int( $max_id ) ) {
		$max_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
		wp_cache_set( 'max_post_id', $max_id, 'dblab', 10 * MINUTE_IN_SECONDS );
	}
	if ( $max_id < 1 ) {
		return 0;
	}

	// ランダムな ID を主キーで引き、候補に当たったものから選ぶ。外れたら引き直す。
	for ( $attempt = 1; $attempt <= DBLAB_SAMPLE_ATTEMPTS; $attempt++ ) {
		$sample_ids = dblab_random_post_ids( $max_id, DBLAB_SAMPLE_SIZE );
		$hits       = $wpdb->get_col( dblab_add_condition( $base_sql, "{$wpdb->posts}.ID IN (" . implode( ',', $sample_ids ) . ')' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $hits ) {
			return (int) $hits[ array_rand( $hits ) ];
		}
	}

	return dblab_pick_from_pool( $base_sql );
}

/**
 * 候補を最大 1,000 件ランダムに選んだ「プール」から 1 件選ぶ（sample 方式で 3 回外れたとき用）。
 *
 * コアと同じく、パスワード条件を外してインデックスだけで読めるようにし、パスワード付きの投稿は
 * 選ぶときに PHP で除く。プールはオブジェクトキャッシュに 10 分置くので、永続オブジェクトキャッシュが
 * ない環境ではリクエストごとに作り直す。
 *
 * @param string $base_sql 候補 ID を返す SELECT 文（ORDER BY / LIMIT なし）。
 * @return int 選んだ投稿 ID。0 なら候補なし。
 */
function dblab_pick_from_pool( string $base_sql ): int {
	global $wpdb;

	$pool_key = 'pool_' . md5( $base_sql );
	$pool     = wp_cache_get( $pool_key, 'dblab' );

	// プールを作る（候補を全件読んで乱数で並べ、先頭 1,000 件）。
	if ( ! is_array( $pool ) ) {
		$password_clause = " AND {$wpdb->posts}.post_password = ''";
		$pool_sql        = 1 === substr_count( $base_sql, $password_clause ) ? str_replace( $password_clause, '', $base_sql ) : $base_sql;
		$pool            = array_map( 'intval', $wpdb->get_col( "{$pool_sql} ORDER BY RAND() LIMIT " . DBLAB_POOL_SIZE ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		wp_cache_set( $pool_key, $pool, 'dblab', 10 * MINUTE_IN_SECONDS );
	}

	// パスワード付きの投稿を除きながら、ランダムな順に見て最初の 1 件を選ぶ。
	shuffle( $pool );
	foreach ( $pool as $post_id ) {
		$post = get_post( $post_id );
		if ( $post instanceof WP_Post && '' === $post->post_password ) {
			return $post_id;
		}
	}
	return 0;
}

/**
 * 1 〜 $max_id から重複しないランダムな ID を選ぶ。
 *
 * @param int $max_id 主キーの最大値。
 * @param int $count  選ぶ数。
 * @return int[] ID の一覧。$max_id が $count 以下なら 1 〜 $max_id のすべて。
 */
function dblab_random_post_ids( int $max_id, int $count ): array {
	if ( $max_id <= $count ) {
		return range( 1, max( 1, $max_id ) );
	}

	$post_ids = array();
	while ( count( $post_ids ) < $count ) {
		$post_ids[ wp_rand( 1, $max_id ) ] = true;
	}
	return array_keys( $post_ids );
}

/**
 * WHERE 句に条件を足す（GROUP BY があればその手前に入れる）。
 *
 * @param string $sql       SELECT 文。
 * @param string $condition 足す条件（AND は不要）。
 * @return string 条件を足した SELECT 文。
 */
function dblab_add_condition( string $sql, string $condition ): string {
	if ( preg_match( '/\sGROUP BY\s/i', $sql ) ) {
		return preg_replace( '/\sGROUP BY\s/i', " AND {$condition} GROUP BY ", $sql, 1 );
	}
	return "{$sql} AND {$condition}";
}

/**
 * 観察用のレスポンスヘッダーを送る。
 *
 * @param string $strategy 使った方式。
 * @param float  $pick_ms  1 件選ぶのにかかった時間（ミリ秒）。
 * @return void
 */
function dblab_send_debug_headers( string $strategy, float $pick_ms ): void {
	if ( headers_sent() ) {
		return;
	}
	header( 'X-DBLab-Strategy: ' . $strategy );
	header( 'X-DBLab-Queries: ' . get_num_queries() );
	header( sprintf( 'X-DBLab-Pick-Ms: %.2f', $pick_ms ) );
}
