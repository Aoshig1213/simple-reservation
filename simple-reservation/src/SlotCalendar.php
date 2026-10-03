<?php
/**
 * 予約枠の計算。WordPress に依存しない（テストしやすくするため、「今」とタイムゾーンは外から渡す）。
 */

namespace SimpleReservation;

final class SlotCalendar {
	public function __construct(
		private Settings $settings,
		private \DateTimeImmutable $now
	) {}

	/** 予約を受け付ける日付の一覧（今日から days_ahead 日後まで、営業日だけ） */
	public function bookable_dates(): array {
		$out   = array();
		$today = $this->now->setTime( 0, 0 );
		for ( $i = 0; $i <= $this->settings->days_ahead; $i++ ) {
			$d = $today->modify( "+{$i} day" );
			if ( $this->is_open_day( $d->format( 'Y-m-d' ) ) && $this->slots( $d->format( 'Y-m-d' ) ) ) {
				$out[] = $d->format( 'Y-m-d' );
			}
		}
		return $out;
	}

	public function is_open_day( string $date ): bool {
		if ( ! Settings::is_date( $date ) || in_array( $date, $this->settings->holidays, true ) ) {
			return false;
		}
		$d = new \DateTimeImmutable( $date, $this->now->getTimezone() );
		return in_array( (int) $d->format( 'w' ), $this->settings->weekdays, true );
	}

	/**
	 * その日の予約枠（開始時刻 "H:i" の一覧）。締め切りを過ぎた枠と、予約できる期間の外は含めない。
	 *
	 * @return string[]
	 */
	public function slots( string $date ): array {
		if ( ! $this->is_open_day( $date ) ) {
			return array();
		}
		$tz    = $this->now->getTimezone();
		$day   = new \DateTimeImmutable( $date, $tz );
		$last  = $this->now->setTime( 0, 0 )->modify( "+{$this->settings->days_ahead} day" );
		if ( $day < $this->now->setTime( 0, 0 ) || $day > $last ) {
			return array();
		}
		$start    = new \DateTimeImmutable( "$date {$this->settings->open_time}", $tz );
		$end      = new \DateTimeImmutable( "$date {$this->settings->close_time}", $tz );
		$deadline = $this->now->modify( "+{$this->settings->min_hours_before} hour" );
		$out      = array();
		// 枠の終わりが閉店時刻を超えないものだけ（例: 10:00〜18:00・60分なら 17:00 が最後）
		for ( $t = $start; $t->modify( "+{$this->settings->slot_minutes} minute" ) <= $end; $t = $t->modify( "+{$this->settings->slot_minutes} minute" ) ) {
			if ( $t >= $deadline ) {
				$out[] = $t->format( 'H:i' );
			}
		}
		return $out;
	}

	public function is_bookable( string $date, string $time ): bool {
		return in_array( $time, $this->slots( $date ), true );
	}

	/**
	 * 枠ごとの残り人数。$booked は「時刻 => 予約済みの人数」。
	 *
	 * @return array<string,int>
	 */
	public function remaining( string $date, array $booked ): array {
		$out = array();
		foreach ( $this->slots( $date ) as $t ) {
			$out[ $t ] = max( 0, $this->settings->capacity - (int) ( $booked[ $t ] ?? 0 ) );
		}
		return $out;
	}
}
