<?php
/**
 * WordPress 7.2 の /random/ の選び方を差し替える。
 *
 * コアが組み立てた /random/ 用の SQL を posts_pre_query で受け取り、並び順・件数・サンプル ID の条件を
 * 外した「候補一覧の SQL」として各方式に渡す。WHERE 句（投稿タイプ・カテゴリー・著者など）はコアが
 * 作ったものをそのまま使うので、/random/category/xxx/ などでも同じ条件で比較できる。
 *
 * @package DB_Load_Lab
 */

defined( 'ABSPATH' ) || exit;

/*
 * 優先度の並びに依存している。コアの wp_random_content_posts_pre_query()（7.2 RC 以降）は優先度 20。
 * 差し替えは 20 より前、コアの所要時間の計測は 20 より後で行う。
 */
add_filter( 'posts_pre_query', 'dblab_maybe_override_random_pick', 10, 2 );
add_filter( 'posts_pre_query', 'dblab_finish_core_pick', 21, 2 );
add_filter( 'wp_redirect', 'dblab_add_pick_headers' );

/**
 * /random/ の 1 件を、選ばれている方式で選ぶ。
 *
 * sample 方式で、コアが sample 方式を持っていれば（7.2 RC 以降）、コアの実装をそのまま走らせる。
 *
 * @param WP_Post[]|int[]|null $posts そのまま返す投稿。null ならクエリを実行する。
 * @param WP_Query             $query クエリ。
 * @return WP_Post[]|int[]|null 選んだ投稿。コアに任せるときは $posts をそのまま返す。
 */
function dblab_maybe_override_random_pick( $posts, WP_Query $query ) {
	if ( null !== $posts || ! function_exists( 'wp_is_random_content_query' ) || ! wp_is_random_content_query( $query ) ) {
		return $posts;
	}

	$strategy = dblab_strategy();

	// 7.2 RC 以降の sample はコアに任せる。かかった時間は dblab_finish_core_pick() で記録する。
	if ( 'sample' === $strategy && function_exists( 'wp_random_content_posts_pre_query' ) ) {
		dblab_start_pick( 'sample' );
		return $posts;
	}

	$candidate_sql = dblab_candidate_sql( $query );
	if ( null === $candidate_sql ) {
		// 想定外の SQL ならコアに任せ、ヘッダーで分かるようにする。
		dblab_start_pick( 'core' );
		return $posts;
	}

	// 方式ごとに 1 件選ぶ。
	dblab_start_pick( $strategy );
	$post_id = dblab_pick_id( $strategy, $candidate_sql );
	dblab_finish_pick();

	if ( ! $post_id ) {
		return array();
	}
	return 'ids' === $query->get( 'fields' ) ? array( $post_id ) : array( get_post( $post_id ) );
}

/**
 * /random/ のメインクエリの SQL から、候補の ID を返す SELECT 文を作る。
 *
 * 7.2 beta まで: 末尾に「ORDER BY RAND() LIMIT 0, 1」が付く。
 * 7.2 RC 以降:   WHERE 句の末尾に「AND wp_posts.ID IN (ランダムな 50 個の ID)」が付く。
 *
 * @param WP_Query $query /random/ のメインクエリ。
 * @return string|null 候補の ID を返す SELECT 文（ORDER BY / LIMIT なし）。想定外の SQL なら null。
 */
function dblab_candidate_sql( WP_Query $query ): ?string {
	global $wpdb;

	// LIKE などのプレースホルダー退避文字はリクエストごとに違うので、元に戻してから扱う。
	$request    = $wpdb->remove_placeholder_escape( (string) $query->request );
	$sample_ids = $query->get( 'random_sample_ids' );

	if ( is_array( $sample_ids ) && $sample_ids ) {
		// 7.2 RC 以降。コアの _wp_random_content_sample_clause() と同じ書式で組み立てて外す。
		$sample_clause = " AND {$wpdb->posts}.ID IN (" . implode( ',', array_map( 'intval', $sample_ids ) ) . ')';
		if ( 1 !== substr_count( $request, $sample_clause ) ) {
			return null;
		}
		$sql = str_replace( $sample_clause, '', $request );
	} else {
		// 7.2 beta まで。
		$sql = preg_replace( '/\s+ORDER BY\s+RAND\(\)\s+LIMIT\s+0,\s*1\s*$/i', '', $request, 1, $replaced );
		if ( 1 !== $replaced ) {
			return null;
		}
	}

	// SELECT 句を ID だけにする（キャッシュする一覧を軽くするため）。
	return preg_replace( '/^\s*SELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?(DISTINCT\s+)?.+?\s+FROM\s/is', "SELECT $1{$wpdb->posts}.ID FROM ", $sql, 1 );
}

/**
 * コアが sample 方式で選び終えた時点の時間を記録する（posts_pre_query、優先度 21）。
 *
 * @param WP_Post[]|int[]|null $posts コアが選んだ投稿。
 * @param WP_Query             $query クエリ。
 * @return WP_Post[]|int[]|null $posts をそのまま返す。
 */
function dblab_finish_core_pick( $posts, WP_Query $query ) {
	if ( function_exists( 'wp_is_random_content_query' ) && wp_is_random_content_query( $query ) ) {
		dblab_finish_pick();
	}
	return $posts;
}

/**
 * 1 件選び始めたことを記録する。
 *
 * @param string $strategy 使う方式。
 * @return void
 */
function dblab_start_pick( string $strategy ): void {
	$GLOBALS['dblab_pick'] = array(
		'strategy' => $strategy,
		'started'  => microtime( true ),
		'ms'       => null,
	);
}

/**
 * 1 件選び終えたことを記録する（記録済みなら何もしない）。
 *
 * @return void
 */
function dblab_finish_pick(): void {
	if ( isset( $GLOBALS['dblab_pick'] ) && null === $GLOBALS['dblab_pick']['ms'] ) {
		$GLOBALS['dblab_pick']['ms'] = ( microtime( true ) - $GLOBALS['dblab_pick']['started'] ) * 1000;
	}
}

/**
 * /random/ のリダイレクトに観察用ヘッダーを付ける（wp_redirect フィルター）。
 *
 * @param string $location リダイレクト先。
 * @return string リダイレクト先（変更しない）。
 */
function dblab_add_pick_headers( $location ) {
	if ( isset( $GLOBALS['dblab_pick'] ) ) {
		$pick = $GLOBALS['dblab_pick'];
		// コアに任せて計測できなかったときは、リダイレクトまでの時間を出す。
		$pick_ms = $pick['ms'] ?? ( microtime( true ) - $pick['started'] ) * 1000;
		dblab_send_debug_headers( $pick['strategy'], $pick_ms );
	}
	return $location;
}
