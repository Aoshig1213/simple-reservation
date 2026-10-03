<?php
/**
 * 予約データの読み書き（$wpdb）。SQL には必ず $wpdb->prepare を通す。
 */

namespace SimpleReservation;

final class Repository {
	public const STATUSES = array(
		'confirmed' => '受付済み',
		'cancelled' => 'キャンセル',
	);

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sr_reservations';
	}

	/** テーブルを作る（有効化のとき） */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$t} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slot_date date NOT NULL,
			slot_time char(5) NOT NULL,
			people smallint(5) unsigned NOT NULL,
			name varchar(100) NOT NULL,
			email varchar(100) NOT NULL,
			phone varchar(20) NOT NULL,
			note text NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'confirmed',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY slot (slot_date, slot_time, status)
			) {$charset};"
		);
	}

	/** その日の「時刻 => 予約済みの人数」（キャンセルは数えない） */
	public static function booked_counts( string $date ): array {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT slot_time, SUM(people) AS n FROM {$t} WHERE slot_date = %s AND status = 'confirmed' GROUP BY slot_time", $date ), ARRAY_A );
		return array_column( $rows ?: array(), 'n', 'slot_time' );
	}

	/**
	 * 定員を確かめて登録する。同じ枠に同時に申し込まれても定員を超えないよう、枠ごとの名前付きロックで1件ずつ処理する。
	 *
	 * @return int|string 登録した ID、または失敗の理由（'full' | 'busy' | 'db'）
	 */
	public static function book( array $v, int $capacity ) {
		global $wpdb;
		$t    = self::table();
		$lock = 'sr_slot_' . md5( $wpdb->prefix . $v['date'] . ' ' . $v['time'] );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) ) ) {
			return 'busy';
		}
		try {
			$booked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(people), 0) FROM {$t} WHERE slot_date = %s AND slot_time = %s AND status = 'confirmed'", $v['date'], $v['time'] ) );
			if ( $booked + $v['people'] > $capacity ) {
				return 'full';
			}
			$ok = $wpdb->insert(
				$t,
				array(
					'slot_date'  => $v['date'],
					'slot_time'  => $v['time'],
					'people'     => $v['people'],
					'name'       => $v['name'],
					'email'      => $v['email'],
					'phone'      => $v['phone'],
					'note'       => $v['note'],
					'status'     => 'confirmed',
					'created_at' => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			return $ok ? (int) $wpdb->insert_id : 'db';
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$t = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ) ?: null;
	}

	public static function set_status( int $id, string $status ): bool {
		global $wpdb;
		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * 一覧（新しい予約日時の順）。$f: from, to（Y-m-d）, status, q（名前・メール・電話）
	 */
	public static function search( array $f, int $limit = 200, int $offset = 0 ): array {
		global $wpdb;
		$t     = self::table();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $f['from'] ) ) {
			$where[] = 'slot_date >= %s';
			$args[]  = $f['from'];
		}
		if ( ! empty( $f['to'] ) ) {
			$where[] = 'slot_date <= %s';
			$args[]  = $f['to'];
		}
		if ( ! empty( $f['status'] ) && isset( self::STATUSES[ $f['status'] ] ) ) {
			$where[] = 'status = %s';
			$args[]  = $f['status'];
		}
		if ( ! empty( $f['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( $f['q'] ) . '%';
			$where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $args, $like, $like, $like );
		}
		$args[] = $limit;
		$args[] = $offset;
		$sql    = "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY slot_date DESC, slot_time DESC, id DESC LIMIT %d OFFSET %d';
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}
}
