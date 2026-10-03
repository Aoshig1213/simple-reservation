<?php
/**
 * 共通の処理（設定の読み出し、「今」の取得、メール）。
 */

namespace SimpleReservation;

final class Plugin {
	public static function settings(): Settings {
		return Settings::from_array( (array) get_option( Settings::OPTION, array() ) );
	}

	public static function calendar( ?Settings $s = null ): SlotCalendar {
		// サイトの設定のタイムゾーンで「今」を決める
		return new SlotCalendar( $s ?? self::settings(), current_datetime() );
	}

	/** 予約の確認メール（お客さま宛て）と通知（管理者宛て） */
	public static function send_mails( array $r ): void {
		$s     = self::settings();
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$when  = self::format_slot( $r['slot_date'], $r['slot_time'] );
		$body  = "{$r['name']} 様\n\nご予約ありがとうございます。次の内容で受け付けました。\n\n";
		$body .= "日時：{$when}\n人数：{$r['people']}名\n";
		$body .= $r['note'] ? "ご要望：{$r['note']}\n" : '';
		$body .= "\n変更・キャンセルは、このメールへの返信かお電話でお知らせください。\n\n{$site}";
		wp_mail( $r['email'], "【{$site}】ご予約を受け付けました（{$when}）", $body );

		$to    = $s->notify_email ?: get_option( 'admin_email' );
		$admin = "新しい予約が入りました。\n\n日時：{$when}\n人数：{$r['people']}名\nお名前：{$r['name']}\nメール：{$r['email']}\n電話：{$r['phone']}\n";
		$admin .= $r['note'] ? "ご要望：{$r['note']}\n" : '';
		$admin .= "\n管理画面：" . admin_url( 'admin.php?page=simple-reservation' );
		wp_mail( $to, "【予約】{$when} {$r['name']}様（{$r['people']}名）", $admin );
	}

	public static function format_slot( string $date, string $time ): string {
		$w = array( '日', '月', '火', '水', '木', '金', '土' );
		$d = new \DateTimeImmutable( $date );
		return $d->format( 'Y年n月j日' ) . '（' . $w[ (int) $d->format( 'w' ) ] . "）{$time}";
	}
}
