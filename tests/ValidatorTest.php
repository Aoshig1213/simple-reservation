<?php

use PHPUnit\Framework\TestCase;
use SimpleReservation\Settings;
use SimpleReservation\SlotCalendar;
use SimpleReservation\Validator;

final class ValidatorTest extends TestCase {
	private function validator(): Validator {
		$s = Settings::from_array( array( 'max_people' => 4 ) );
		return new Validator( $s, new SlotCalendar( $s, new DateTimeImmutable( '2026-10-01 09:00', new DateTimeZone( 'Asia/Tokyo' ) ) ) );
	}

	private function ok(): array {
		return array( 'date' => '2026-10-05', 'time' => '10:00', 'people' => '2', 'name' => '山田 花子', 'email' => 'hanako@example.com', 'phone' => '090-1234-5678', 'note' => '' );
	}

	public function test_正しい入力(): void {
		list( $v, $e ) = $this->validator()->validate( $this->ok() );
		$this->assertSame( array(), $e );
		$this->assertSame( 2, $v['people'] );
	}

	public function test_全角の電話番号を半角にそろえる(): void {
		list( $v, $e ) = $this->validator()->validate( array( 'phone' => '０９０ー１２３４ー５６７８' ) + $this->ok() );
		$this->assertSame( '090-1234-5678', $v['phone'] );
		$this->assertArrayNotHasKey( 'phone', $e );
	}

	public function test_項目ごとのエラー(): void {
		list( , $e ) = $this->validator()->validate( array( 'time' => '10:30', 'people' => '5', 'name' => '', 'email' => 'x@', 'phone' => '1234' ) + $this->ok() );
		$this->assertSame( array( 'time', 'people', 'name', 'email', 'phone' ), array_keys( $e ) );
	}

	public function test_制御文字は取り除き、ご要望の改行は残す(): void {
		list( $v ) = $this->validator()->validate( array( 'name' => "山田\x00花子\n", 'note' => "1行目\r\n2行目\x07" ) + $this->ok() );
		$this->assertSame( '山田花子', $v['name'] );
		$this->assertSame( "1行目\n2行目", $v['note'] );
	}
}
