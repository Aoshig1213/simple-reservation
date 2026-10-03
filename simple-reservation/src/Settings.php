<?php
/**
 * 予約受付の設定（営業日・時間・定員など）。WordPress のオプションに保存する値を、型のそろった形で扱う。
 */

namespace SimpleReservation;

final class Settings {
	public const OPTION = 'simple_reservation_settings';

	/** @var int[] 受け付ける曜日（0=日曜 … 6=土曜） */
	public array $weekdays = array( 1, 2, 3, 4, 5, 6 );
	public string $open_time  = '10:00';
	public string $close_time = '18:00';
	public int $slot_minutes  = 60;
	/** 1枠あたりの定員（人数） */
	public int $capacity = 3;
	/** 1件で予約できる最大人数 */
	public int $max_people = 4;
	/** 何日先まで予約できるか */
	public int $days_ahead = 30;
	/** 開始の何時間前で締め切るか */
	public int $min_hours_before = 3;
	/** @var string[] 休業日（Y-m-d） */
	public array $holidays = array();
	/** 予約の通知を受け取るメールアドレス（空ならサイトの管理者） */
	public string $notify_email = '';

	public static function from_array( array $a ): self {
		$s = new self();
		if ( isset( $a['weekdays'] ) && is_array( $a['weekdays'] ) ) {
			$s->weekdays = array_values( array_unique( array_filter( array_map( 'intval', $a['weekdays'] ), fn( $d ) => $d >= 0 && $d <= 6 ) ) );
			sort( $s->weekdays );
		}
		foreach ( array( 'open_time', 'close_time' ) as $k ) {
			if ( isset( $a[ $k ] ) && preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $a[ $k ] ) ) {
				$s->$k = (string) $a[ $k ];
			}
		}
		$ints = array(
			'slot_minutes'     => array( 10, 480 ),
			'capacity'         => array( 1, 100 ),
			'max_people'       => array( 1, 100 ),
			'days_ahead'       => array( 1, 365 ),
			'min_hours_before' => array( 0, 168 ),
		);
		foreach ( $ints as $k => list( $min, $max ) ) {
			if ( isset( $a[ $k ] ) && is_numeric( $a[ $k ] ) ) {
				$s->$k = max( $min, min( $max, (int) $a[ $k ] ) );
			}
		}
		if ( isset( $a['holidays'] ) ) {
			$lines       = is_array( $a['holidays'] ) ? $a['holidays'] : preg_split( '/[\r\n,]+/', (string) $a['holidays'] );
			$s->holidays = array_values( array_unique( array_filter( array_map( 'trim', $lines ), array( self::class, 'is_date' ) ) ) );
			sort( $s->holidays );
		}
		if ( isset( $a['notify_email'] ) ) {
			$s->notify_email = trim( (string) $a['notify_email'] );
		}
		return $s;
	}

	public function to_array(): array {
		return get_object_vars( $this );
	}

	public static function is_date( string $d ): bool {
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $d );
		return $dt && $dt->format( 'Y-m-d' ) === $d;
	}
}
