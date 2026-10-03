<?php

use PHPUnit\Framework\TestCase;
use SimpleReservation\Settings;
use SimpleReservation\SlotCalendar;
use SimpleReservation\Validator;

final class SlotCalendarTest extends TestCase {
	private function cal( string $now, array $s = array() ): SlotCalendar {
		$tz = new DateTimeZone( 'Asia/Tokyo' );
		return new SlotCalendar( Settings::from_array( $s ), new DateTimeImmutable( $now, $tz ) );
	}

	public function test_枠は閉店時刻を超えない(): void {
		// 2026-10-05 は月曜。10:00〜18:00、60分 → 17:00 が最後
		$slots = $this->cal( '2026-10-01 09:00' )->slots( '2026-10-05' );
		$this->assertSame( array( '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00', '17:00' ), $slots );
	}

	public function test_半端な時間は最後の枠を作らない(): void {
		$slots = $this->cal( '2026-10-01 09:00', array( 'open_time' => '10:00', 'close_time' => '12:30', 'slot_minutes' => 45 ) )->slots( '2026-10-05' );
		$this->assertSame( array( '10:00', '10:45', '11:30' ), $slots ); // 12:15 から 45分だと 13:00 になるので出さない
	}

	public function test_定休日と休業日は予約できない(): void {
		$c = $this->cal( '2026-10-01 09:00', array( 'weekdays' => array( 1, 2, 3, 4, 5 ), 'holidays' => "2026-10-06\n2026-10-07" ) );
		$this->assertSame( array(), $c->slots( '2026-10-04' ) ); // 日曜
		$this->assertSame( array(), $c->slots( '2026-10-06' ) ); // 休業日
		$this->assertNotSame( array(), $c->slots( '2026-10-08' ) );
	}

	public function test_締め切りを過ぎた枠は出さない(): void {
		// 今が 10/5 12:10、3時間前で締め切り → 15:10 以降に始まる枠だけ
		$this->assertSame( array( '16:00', '17:00' ), $this->cal( '2026-10-05 12:10' )->slots( '2026-10-05' ) );
	}

	public function test_予約できる期間の外は出さない(): void {
		$c = $this->cal( '2026-10-05 09:00', array( 'days_ahead' => 7 ) );
		$this->assertSame( array(), $c->slots( '2026-10-04' ) ); // 過去
		$this->assertNotSame( array(), $c->slots( '2026-10-12' ) ); // ちょうど7日後
		$this->assertSame( array(), $c->slots( '2026-10-13' ) ); // 8日後
	}

	public function test_予約できる日の一覧(): void {
		$dates = $this->cal( '2026-10-05 09:00', array( 'days_ahead' => 7, 'weekdays' => array( 1, 3 ) ) )->bookable_dates();
		$this->assertSame( array( '2026-10-05', '2026-10-07', '2026-10-12' ), $dates );
	}

	public function test_残り人数(): void {
		$r = $this->cal( '2026-10-01 09:00', array( 'capacity' => 3 ) )->remaining( '2026-10-05', array( '10:00' => 3, '11:00' => 1, '12:00' => 5 ) );
		$this->assertSame( 0, $r['10:00'] );
		$this->assertSame( 2, $r['11:00'] );
		$this->assertSame( 0, $r['12:00'] ); // 手作業で入れて定員を超えていても、マイナスにはしない
		$this->assertSame( 3, $r['13:00'] );
	}

	public function test_存在しない日付は受け付けない(): void {
		$c = $this->cal( '2026-01-01 09:00', array( 'days_ahead' => 365 ) );
		$this->assertFalse( $c->is_open_day( '2026-02-30' ) );
		$this->assertFalse( $c->is_open_day( '2026/03/02' ) );
	}

	public function test_設定の範囲外の値は丸める(): void {
		$s = Settings::from_array( array( 'capacity' => 0, 'slot_minutes' => 99999, 'open_time' => '25:00', 'weekdays' => array( 9, '1', 1 ) ) );
		$this->assertSame( 1, $s->capacity );
		$this->assertSame( 480, $s->slot_minutes );
		$this->assertSame( '10:00', $s->open_time );
		$this->assertSame( array( 1 ), $s->weekdays );
	}
}

