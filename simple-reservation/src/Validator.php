<?php
/**
 * 予約フォームの入力チェック。値をきれいにした上で、項目ごとのエラーを返す。
 */

namespace SimpleReservation;

final class Validator {
	public function __construct( private Settings $settings, private SlotCalendar $calendar ) {}

	/**
	 * @return array{0: array<string,mixed>, 1: array<string,string>} [きれいにした値, エラー]
	 */
	public function validate( array $in ): array {
		$v = array(
			'date'   => trim( (string) ( $in['date'] ?? '' ) ),
			'time'   => trim( (string) ( $in['time'] ?? '' ) ),
			'people' => (int) ( $in['people'] ?? 0 ),
			'name'   => self::clean( (string) ( $in['name'] ?? '' ) ),
			'email'  => trim( (string) ( $in['email'] ?? '' ) ),
			'phone'  => self::phone( (string) ( $in['phone'] ?? '' ) ),
			'note'   => self::clean( (string) ( $in['note'] ?? '' ), true ),
		);
		$e = array();

		if ( ! $this->calendar->is_bookable( $v['date'], $v['time'] ) ) {
			$e['time'] = 'この日時は予約を受け付けていません。別の枠を選んでください';
		}
		if ( $v['people'] < 1 || $v['people'] > $this->settings->max_people ) {
			$e['people'] = sprintf( '人数は1〜%d人で選んでください', $this->settings->max_people );
		}
		if ( '' === $v['name'] ) {
			$e['name'] = 'お名前を入力してください';
		} elseif ( mb_strlen( $v['name'] ) > 50 ) {
			$e['name'] = 'お名前は50文字以内で入力してください';
		}
		if ( ! filter_var( $v['email'], FILTER_VALIDATE_EMAIL ) || mb_strlen( $v['email'] ) > 100 ) {
			$e['email'] = 'メールアドレスを正しく入力してください';
		}
		$digits = preg_replace( '/\D/', '', $v['phone'] );
		if ( '' === $digits ) {
			$e['phone'] = '電話番号を入力してください';
		} elseif ( ! preg_match( '/^0\d{9,10}$/', $digits ) ) {
			$e['phone'] = '電話番号は0から始まる10〜11桁で入力してください';
		}
		if ( mb_strlen( $v['note'] ) > 500 ) {
			$e['note'] = 'ご要望は500文字以内で入力してください';
		}
		return array( $v, $e );
	}

	/** 全角の数字・ハイフンを半角にそろえ、数字とハイフン以外を取り除く */
	private static function phone( string $s ): string {
		$s = str_replace( array( 'ー', '－', '−', '‐' ), '-', mb_convert_kana( trim( $s ), 'n' ) );
		return preg_replace( '/[^\d\-]/', '', $s ) ?? '';
	}

	/** 制御文字を取り除く。改行は note だけ残す */
	private static function clean( string $s, bool $multiline = false ): string {
		$s = str_replace( array( "\r\n", "\r" ), "\n", $s );
		$s = preg_replace( $multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u', '', $s ) ?? '';
		return trim( $s );
	}
}
