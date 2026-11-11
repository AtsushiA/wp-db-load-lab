<?php
/**
 * Plugin Name: DB Load Lab
 * Description: ミートアップ学習用。ランダムに1件選ぶ方式ごとの DB 負荷を比較するための実験プラグイン。本番サイトには入れないでください。
 * Version:     1.0.0
 *
 * 1) WordPress 7.2 の /random/ の「選び方」を差し替える
 *      /random/?dblab_strategy=offset     … そのリクエストだけ差し替え
 *      wp option update dblab_strategy offset  … サイト全体で差し替え（負荷をかけたまま切り替えられる）
 *
 * 2) 7.2 未満でも試せる単体エンドポイント
 *      /?dblab=rand | offset | idrange | cached | latest | none
 *      &go=1 を付けると選んだ記事へ 302 リダイレクト
 *
 * 方式:
 *   rand    … ORDER BY RAND() LIMIT 1（7.2 コアと同じ）。候補を全件読んで乱数を振り、並べ替える
 *   offset  … COUNT(*) で件数を数え、ランダムな OFFSET で1件取る。並べ替え・一時テーブルは不要だが OFFSET 分は読み飛ばす
 *   idrange … MIN〜MAX の ID から乱数を選び ID >= x の最初の1件。ほぼ一瞬だが ID の歯抜けがあると偏る
 *   cached  … 候補 ID 一覧を transient に 5 分キャッシュし PHP で選ぶ。DB は軽いが一覧の鮮度と容量がトレードオフ
 */

defined( 'ABSPATH' ) || exit;

// 事故防止：local / development 環境以外では何もしない。
if ( ! in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
	return;
}

const DBLAB_STRATEGIES = array( 'rand', 'offset', 'idrange', 'cached' );

/**
 * 今回のリクエストで使う方式。
 */
function dblab_strategy(): string {
	$strategy = isset( $_GET['dblab_strategy'] ) ? sanitize_key( wp_unslash( $_GET['dblab_strategy'] ) ) : get_option( 'dblab_strategy', 'rand' );
	return in_array( $strategy, DBLAB_STRATEGIES, true ) ? $strategy : 'rand';
}

/**
 * 「ORDER BY / LIMIT を除いた、候補 ID を返す SELECT 文」から 1 件の ID を選ぶ。
 *
 * @param string $strategy 方式。
 * @param string $base_sql 例: SELECT wp_posts.ID FROM wp_posts WHERE ... （GROUP BY 付きでも可）
 * @return int 0 なら候補なし。
 */
function dblab_pick_id( string $strategy, string $base_sql ): int {
	global $wpdb;

	switch ( $strategy ) {
		case 'offset':
			if ( preg_match( '/\sGROUP BY\s/i', $base_sql ) ) {
				// タクソノミーアーカイブは GROUP BY が付くので、サブクエリで包んで数える。
				$count_sql = "SELECT COUNT(*) FROM ( {$base_sql} ) AS dblab_candidates";
			} else {
				$count_sql = preg_replace( '/^\s*SELECT\s.+?\sFROM\s/is', 'SELECT COUNT(*) FROM ', $base_sql, 1 );
			}
			$count = (int) $wpdb->get_var( $count_sql );
			if ( $count < 1 ) {
				return 0;
			}
			$offset = wp_rand( 0, $count - 1 );
			return (int) $wpdb->get_var( "{$base_sql} LIMIT {$offset}, 1" );

		case 'idrange':
			// MIN/MAX は主キーの端を見るだけなので一瞬。
			$range = $wpdb->get_row( "SELECT MIN(ID) AS min_id, MAX(ID) AS max_id FROM {$wpdb->posts}" );
			if ( ! $range || ! $range->max_id ) {
				return 0;
			}
			$pivot = wp_rand( (int) $range->min_id, (int) $range->max_id );
			$id    = (int) $wpdb->get_var( dblab_add_condition( $base_sql, "{$wpdb->posts}.ID >= {$pivot}" ) . " ORDER BY {$wpdb->posts}.ID ASC LIMIT 1" );
			if ( ! $id ) {
				// pivot より後ろに候補がなければ先頭から。
				$id = (int) $wpdb->get_var( "{$base_sql} ORDER BY {$wpdb->posts}.ID ASC LIMIT 1" );
			}
			return $id;

		case 'cached':
			$key = 'dblab_ids_' . md5( $base_sql );
			$ids = get_transient( $key );
			if ( false === $ids ) {
				$ids = array_map( 'intval', $wpdb->get_col( $base_sql ) );
				set_transient( $key, $ids, 5 * MINUTE_IN_SECONDS );
			}
			return $ids ? (int) $ids[ array_rand( $ids ) ] : 0;

		case 'rand':
		default:
			return (int) $wpdb->get_var( "{$base_sql} ORDER BY RAND() LIMIT 0, 1" );
	}
}

/**
 * WHERE 句に条件を足す（GROUP BY があればその手前に入れる）。
 */
function dblab_add_condition( string $sql, string $condition ): string {
	if ( preg_match( '/\sGROUP BY\s/i', $sql ) ) {
		return preg_replace( '/\sGROUP BY\s/i', " AND {$condition} GROUP BY ", $sql, 1 );
	}
	return "{$sql} AND {$condition}";
}

/**
 * 観察用ヘッダー。
 */
function dblab_debug_headers( string $strategy, float $started ): void {
	if ( headers_sent() ) {
		return;
	}
	header( 'X-DBLab-Strategy: ' . $strategy );
	header( 'X-DBLab-Queries: ' . get_num_queries() );
	header( sprintf( 'X-DBLab-Pick-Ms: %.2f', ( microtime( true ) - $started ) * 1000 ) );
}

/*
 * ------------------------------------------------------------
 * 1) WordPress 7.2 の /random/ の選び方を差し替え
 * ------------------------------------------------------------
 * コアは ORDER BY RAND() LIMIT 0, 1 の SQL を組み立てる。posts_pre_query で
 * その SQL を受け取り、ORDER BY / LIMIT を外した「候補一覧の SQL」として各方式に渡す。
 * WHERE 句（投稿タイプ・カテゴリー・著者など）はコアが作ったものをそのまま使うので、
 * /random/category/xxx/ などでも同じ条件で比較できる。
 */
add_filter(
	'posts_pre_query',
	static function ( $posts, WP_Query $query ) {
		global $wpdb;

		if ( null !== $posts || ! function_exists( 'wp_is_random_content_query' ) || ! wp_is_random_content_query( $query ) ) {
			return $posts;
		}

		$strategy = dblab_strategy();
		$started  = microtime( true );

		if ( 'rand' === $strategy ) {
			// コアの処理をそのまま走らせる（ヘッダーだけ付ける）。
			add_filter(
				'wp_redirect',
				static function ( $location ) use ( $started ) {
					dblab_debug_headers( 'rand', $started );
					return $location;
				}
			);
			return $posts;
		}

		$base = preg_replace( '/\s+ORDER BY\s+RAND\(\)\s+LIMIT\s+0,\s*1\s*$/i', '', $query->request, 1, $replaced );
		if ( 1 !== $replaced ) {
			return $posts; // 想定外の SQL ならコアに任せる。
		}
		// SELECT 句を ID だけにする（キャッシュする一覧を軽くするため）。
		$base = preg_replace( '/^\s*SELECT\s+(?:SQL_CALC_FOUND_ROWS\s+)?(DISTINCT\s+)?.+?\s+FROM\s/is', "SELECT $1{$wpdb->posts}.ID FROM ", $base, 1 );

		$id = dblab_pick_id( $strategy, $base );

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( $strategy, $started ) {
				dblab_debug_headers( $strategy, $started );
				return $location;
			}
		);

		if ( ! $id ) {
			return array();
		}
		return 'ids' === $query->get( 'fields' ) ? array( $id ) : array( get_post( $id ) );
	},
	10,
	2
);

/*
 * ------------------------------------------------------------
 * 2) 単体エンドポイント /?dblab=MODE
 * ------------------------------------------------------------
 */
add_action(
	'init',
	static function () {
		global $wpdb;

		if ( ! isset( $_GET['dblab'] ) ) {
			return;
		}
		$mode    = sanitize_key( wp_unslash( $_GET['dblab'] ) );
		$started = microtime( true );
		$base    = "SELECT {$wpdb->posts}.ID FROM {$wpdb->posts} WHERE {$wpdb->posts}.post_password = '' AND {$wpdb->posts}.post_type = 'post' AND {$wpdb->posts}.post_status = 'publish'";

		if ( 'none' === $mode ) {
			// WordPress の起動だけのコスト（比較の基準線）。
			$id = 0;
		} elseif ( 'latest' === $mode ) {
			// 普通の「最新記事 1 件」。インデックスで即座に取れる。
			$id = (int) $wpdb->get_var( "{$base} ORDER BY {$wpdb->posts}.post_date DESC LIMIT 0, 1" );
		} elseif ( in_array( $mode, DBLAB_STRATEGIES, true ) ) {
			$id = dblab_pick_id( $mode, $base );
		} else {
			status_header( 400 );
			echo 'unknown mode. use: none, latest, ' . esc_html( implode( ', ', DBLAB_STRATEGIES ) );
			exit;
		}

		nocache_headers();
		dblab_debug_headers( $mode, $started );

		if ( $id && ! empty( $_GET['go'] ) ) {
			wp_safe_redirect( get_permalink( $id ), 302 );
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		printf(
			"mode=%s id=%d queries=%d pick_ms=%.2f\n",
			esc_html( $mode ),
			$id,
			get_num_queries(),
			( microtime( true ) - $started ) * 1000
		);
		exit;
	},
	99
);
