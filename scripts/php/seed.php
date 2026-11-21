<?php
/**
 * 学習用の投稿を SQL で一気に作る（wp post generate より桁違いに速い）。
 *
 *   wp eval-file /lab/seed.php <件数> [本文KB] [1 記事あたりのリビジョン数]
 *
 * 以前このスクリプトで作った投稿は削除してから作り直すので、件数は「合計がこの数になる」指定。
 * 投稿は 5 つのカテゴリー（Lab A〜E）に順番に振り分ける。
 *
 * リビジョン数を指定すると、各記事の直後に本文が空のリビジョンを作る。投稿 ID のうち記事が占める割合
 * （ID の密度）が 1 / (リビジョン数 + 1) に下がり、実際のサイトに近い状態で sample 方式（7.2 RC のコア）の
 * 「ランダムな ID が外れる」動きを観察できる。
 */

global $wpdb;

$target     = isset( $args[0] ) ? max( 0, (int) $args[0] ) : 10000;
$content_kb = isset( $args[1] ) ? max( 0, (int) $args[1] ) : 2;
$revisions  = isset( $args[2] ) ? min( 99, max( 0, (int) $args[2] ) ) : 0;
$marker     = 'dblab';
$started    = microtime( true );

// 1) 以前の学習用投稿を削除。
$wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.post_content_filtered = '{$marker}'" );
$deleted = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_content_filtered = '{$marker}'" );
WP_CLI::log( "削除: {$deleted} 件" );

// 2) カテゴリーを用意。
$tt_ids = array();
foreach ( array( 'Lab A', 'Lab B', 'Lab C', 'Lab D', 'Lab E' ) as $name ) {
	$term = term_exists( $name, 'category' );
	if ( ! $term ) {
		$term = wp_insert_term( $name, 'category', array( 'slug' => sanitize_title( $name ) ) );
	}
	$tt_ids[] = (int) $term['term_taxonomy_id'];
}

// 3) 本文（指定 KB 程度）。
$sentence = 'これは DB 負荷を観察するための学習用ダミー記事です。The quick brown fox jumps over the lazy dog. ';
$content  = $content_kb > 0 ? str_repeat( $sentence, (int) ceil( $content_kb * 1024 / strlen( $sentence ) ) ) : '';

// 4) 0〜9999 の連番を作るサブクエリで、1 万記事ずつ INSERT ... SELECT。
//    リビジョン番号 r（0 が記事本体、1 以降がリビジョン）と掛け合わせ、記事 → そのリビジョンの順に ID を振る。
$digit      = '(SELECT 0 i UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
$series     = "SELECT a.i + b.i * 10 + c.i * 100 + d.i * 1000 AS n FROM {$digit} a CROSS JOIN {$digit} b CROSS JOIN {$digit} c CROSS JOIN {$digit} d";
$rev_series = "SELECT a.i + b.i * 10 AS r FROM {$digit} a CROSS JOIN {$digit} b";

// INSERT ... SELECT は件数を事前に見積もれないため、InnoDB が AUTO_INCREMENT を倍々に先取りし、
// 余った分が欠番になる（1 万件で約 6,000 個）。欠番があると idrange 方式の選び方が偏るので、
// バッチの前後で「現在の最大 ID + 1」に詰め直す（AUTO_INCREMENT = 1 を指定すると MAX(ID)+1 になる）。
$compact_auto_increment = static function () use ( $wpdb ) {
	$wpdb->query( "ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 1" );
};

for ( $start = 0; $start < $target; $start += 10000 ) {
	$compact_auto_increment();
	// ORDER BY n, r の順に AUTO_INCREMENT が振られるので、各記事の直後にそのリビジョンが並ぶ。
	$sql = $wpdb->prepare(
		"INSERT INTO {$wpdb->posts}
			(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, post_name, to_ping, pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent, guid, menu_order, post_type, post_mime_type, comment_count)
		SELECT 1, d, d, IF( r = 0, %s, '' ), CONCAT('学習用記事 #', n), '', IF( r = 0, 'publish', 'inherit' ), 'closed', 'closed', '',
			IF( r = 0, CONCAT('lab-post-', n), CONCAT('lab-post-', n, '-revision-v', r) ), '', '', d, d, %s, 0, '', 0, IF( r = 0, 'post', 'revision' ), '', 0
		FROM ( SELECT s.n + %d AS n, DATE_SUB( NOW(), INTERVAL ( s.n + %d ) MINUTE ) AS d, v.r FROM ( {$series} ) s CROSS JOIN ( {$rev_series} ) v WHERE s.n + %d < %d AND v.r <= %d ) t
		ORDER BY n, r",
		$content,
		$marker,
		$start,
		$start,
		$start,
		$target,
		$revisions
	);
	$wpdb->query( $sql );
	WP_CLI::log( sprintf( '作成: %d / %d 記事', min( $start + 10000, $target ), $target ) );
}
$compact_auto_increment();

// リビジョンの親（post_parent）を、post_name でひも付けた記事の ID にする。
if ( $revisions > 0 ) {
	$wpdb->query(
		"UPDATE {$wpdb->posts} rev
		INNER JOIN {$wpdb->posts} p ON p.post_name = SUBSTRING_INDEX( rev.post_name, '-revision-', 1 ) AND p.post_type = 'post' AND p.post_content_filtered = '{$marker}'
		SET rev.post_parent = p.ID
		WHERE rev.post_type = 'revision' AND rev.post_content_filtered = '{$marker}'"
	);
}

// 5) guid とカテゴリー。
$home = esc_sql( home_url( '/' ) );
$wpdb->query( "UPDATE {$wpdb->posts} SET guid = CONCAT('{$home}?p=', ID) WHERE post_content_filtered = '{$marker}' AND guid = ''" );

$elt = implode( ', ', $tt_ids );
// カテゴリーは記事だけに付ける。ID ではなく作成順の連番で振り分け、リビジョン数によらず 5 等分にする。
$wpdb->query( "INSERT INTO {$wpdb->term_relationships} (object_id, term_taxonomy_id, term_order) SELECT ID, ELT(CAST(SUBSTRING(post_name, 10) AS UNSIGNED) % 5 + 1, {$elt}), 0 FROM {$wpdb->posts} WHERE post_content_filtered = '{$marker}' AND post_type = 'post'" );
wp_update_term_count_now( $tt_ids, 'category' );

// 6) キャッシュと transient を掃除。
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '%dblab\\_ids\\_%'" );
wp_cache_flush();

// 7) 件数と ID の密度（1〜MAX(ID) のうち公開済み投稿が占める割合）を表示。
$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" );
$max_id = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" );
WP_CLI::success(
	sprintf(
		'公開済み投稿: %s 件、リビジョン: 1 記事あたり %d 件、ID の密度: %.1f%%（%.1f 秒）',
		number_format( $total ),
		$revisions,
		$max_id > 0 ? $total / $max_id * 100 : 0,
		microtime( true ) - $started
	)
);
