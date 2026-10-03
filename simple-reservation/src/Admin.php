<?php
/**
 * 管理画面（予約一覧・キャンセル・CSV 出力・設定）。予約の操作は manage_options を持つ人だけ。
 */

namespace SimpleReservation;

final class Admin {
	private const CAP = 'manage_options';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'settings' ) );
		add_action( 'admin_post_sr_status', array( self::class, 'change_status' ) );
		add_action( 'admin_post_sr_export', array( self::class, 'export' ) );
	}

	public static function menu(): void {
		add_menu_page( '予約', '予約', self::CAP, 'simple-reservation', array( self::class, 'list_page' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( 'simple-reservation', '予約一覧', '予約一覧', self::CAP, 'simple-reservation', array( self::class, 'list_page' ) );
		add_submenu_page( 'simple-reservation', '予約の設定', '設定', self::CAP, 'simple-reservation-settings', array( self::class, 'settings_page' ) );
	}

	private static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 絞り込みの表示だけ
		$f = array();
		foreach ( array( 'from', 'to', 'status', 'q' ) as $k ) {
			$f[ $k ] = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
		}
		// phpcs:enable
		foreach ( array( 'from', 'to' ) as $k ) {
			if ( $f[ $k ] && ! Settings::is_date( $f[ $k ] ) ) {
				$f[ $k ] = '';
			}
		}
		return $f;
	}

	public static function list_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$f    = self::filters();
		$rows = Repository::search( $f );
		echo '<div class="wrap"><h1 class="wp-heading-inline">予約一覧</h1>';
		printf( ' <a class="page-title-action" href="%s">CSV で書き出す</a><hr class="wp-header-end">', esc_url( wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'sr_export' ), array_filter( $f ) ), admin_url( 'admin-post.php' ) ), 'sr_export' ) ) );
		if ( isset( $_GET['sr_updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>予約の状態を変更しました。</p></div>';
		}
		echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="simple-reservation">';
		printf( '<label>予約日 <input type="date" name="from" value="%s"></label> 〜 <input type="date" name="to" value="%s"> ', esc_attr( $f['from'] ), esc_attr( $f['to'] ) );
		echo '<select name="status"><option value="">すべての状態</option>';
		foreach ( Repository::STATUSES as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $k, $f['status'], false ), esc_html( $label ) );
		}
		printf( '</select> <input type="search" name="q" value="%s" placeholder="名前・メール・電話"> ', esc_attr( $f['q'] ) );
		submit_button( '絞り込む', 'secondary', '', false );
		echo '</form>';

		echo '<table class="widefat striped"><thead><tr><th>予約日時</th><th>人数</th><th>お名前</th><th>連絡先</th><th>ご要望</th><th>状態</th><th>受付日時</th><th></th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="8">予約はありません。</td></tr>';
		}
		foreach ( $rows as $r ) {
			$cancelled = 'cancelled' === $r['status'];
			echo '<tr' . ( $cancelled ? ' style="opacity:.55"' : '' ) . '>';
			printf( '<td>%s</td><td>%d名</td><td>%s</td>', esc_html( Plugin::format_slot( $r['slot_date'], $r['slot_time'] ) ), (int) $r['people'], esc_html( $r['name'] ) );
			printf( '<td><a href="mailto:%1$s">%1$s</a><br>%2$s</td>', esc_html( $r['email'] ), esc_html( $r['phone'] ) );
			printf( '<td>%s</td><td>%s</td><td>%s</td><td>', nl2br( esc_html( $r['note'] ) ), esc_html( Repository::STATUSES[ $r['status'] ] ?? $r['status'] ), esc_html( $r['created_at'] ) );
			$to = $cancelled ? 'confirmed' : 'cancelled';
			printf(
				'<form method="post" action="%s" onsubmit="return confirm(%s)">%s<input type="hidden" name="action" value="sr_status"><input type="hidden" name="id" value="%d"><input type="hidden" name="status" value="%s"><button class="button%s">%s</button></form>',
				esc_url( admin_url( 'admin-post.php' ) ),
				esc_attr( wp_json_encode( $cancelled ? 'この予約を受付済みに戻します。よろしいですか？' : 'この予約をキャンセルにします。よろしいですか？' ) ),
				wp_nonce_field( 'sr_status_' . $r['id'], '_wpnonce', true, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				(int) $r['id'],
				esc_attr( $to ),
				$cancelled ? '' : ' button-link-delete',
				$cancelled ? '受付済みに戻す' : 'キャンセルにする'
			);
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	public static function change_status(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'sr_status_' . $id ) ) {
			wp_die( esc_html( '権限がありません。' ), '', array( 'response' => 403 ) );
		}
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$r      = Repository::get( $id );
		// 受付済みに戻すときは、その間に枠が埋まっていないかを確かめる
		if ( $r && 'confirmed' === $status && 'cancelled' === $r['status'] ) {
			$booked = (int) ( Repository::booked_counts( $r['slot_date'] )[ $r['slot_time'] ] ?? 0 );
			if ( $booked + (int) $r['people'] > Plugin::settings()->capacity ) {
				wp_die( esc_html( 'この枠は、ほかの予約で定員に達しているため戻せません。' ), '', array( 'response' => 409, 'back_link' => true ) );
			}
		}
		Repository::set_status( $id, $status );
		wp_safe_redirect( add_query_arg( 'sr_updated', 1, wp_get_referer() ?: admin_url( 'admin.php?page=simple-reservation' ) ) );
		exit;
	}

	/** CSV（Excel で開けるよう BOM 付き UTF-8）。先頭が = + - @ の値は、数式として動かないよう ' を付ける */
	public static function export(): void {
		if ( ! current_user_can( self::CAP ) || ! check_admin_referer( 'sr_export' ) ) {
			wp_die( esc_html( '権限がありません。' ), '', array( 'response' => 403 ) );
		}
		$rows = Repository::search( self::filters(), 100000 );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="reservations-' . gmdate( 'Ymd' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( '予約日', '時間', '人数', 'お名前', 'メールアドレス', '電話番号', 'ご要望', '状態', '受付日時' ) );
		$safe = fn( $v ) => preg_match( '/^[=+\-@\t\r]/', (string) $v ) ? "'" . $v : $v;
		foreach ( $rows as $r ) {
			fputcsv( $out, array_map( $safe, array( $r['slot_date'], $r['slot_time'], $r['people'], $r['name'], $r['email'], $r['phone'], $r['note'], Repository::STATUSES[ $r['status'] ] ?? $r['status'], $r['created_at'] ) ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	// ---------- 設定 ----------
	public static function settings(): void {
		register_setting(
			'simple_reservation',
			Settings::OPTION,
			array(
				'type'              => 'array',
				// 曜日のチェックを全部外すと項目ごと届かないので、「選ばれていない」として扱う
				'sanitize_callback' => fn( $in ) => Settings::from_array( ( is_array( $in ) ? $in : array() ) + array( 'weekdays' => array() ) )->to_array(),
				'default'           => ( new Settings() )->to_array(),
			)
		);
	}

	public static function settings_page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s    = Plugin::settings();
		$name = fn( $k ) => esc_attr( Settings::OPTION . "[$k]" );
		echo '<div class="wrap"><h1>予約の設定</h1><form method="post" action="options.php">';
		settings_fields( 'simple_reservation' );
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th>受け付ける曜日</th><td>';
		foreach ( array( '日', '月', '火', '水', '木', '金', '土' ) as $i => $w ) {
			printf( '<label style="margin-right:12px"><input type="checkbox" name="%s[]" value="%d"%s> %s</label>', $name( 'weekdays' ), (int) $i, checked( in_array( $i, $s->weekdays, true ), true, false ), esc_html( $w ) );
		}
		echo '</td></tr>';
		printf( '<tr><th>受付時間</th><td><input type="time" name="%s" value="%s"> 〜 <input type="time" name="%s" value="%s"></td></tr>', $name( 'open_time' ), esc_attr( $s->open_time ), $name( 'close_time' ), esc_attr( $s->close_time ) );
		$nums = array(
			'slot_minutes'     => array( '1枠の長さ', '分' ),
			'capacity'         => array( '1枠の定員', '名' ),
			'max_people'       => array( '1件で予約できる人数', '名まで' ),
			'days_ahead'       => array( '何日先まで受け付けるか', '日先まで' ),
			'min_hours_before' => array( '締め切り', '時間前まで' ),
		);
		foreach ( $nums as $k => list( $label, $unit ) ) {
			printf( '<tr><th><label for="sr-%1$s">%2$s</label></th><td><input id="sr-%1$s" type="number" class="small-text" name="%3$s" value="%4$d"> %5$s</td></tr>', esc_attr( $k ), esc_html( $label ), $name( $k ), (int) $s->$k, esc_html( $unit ) );
		}
		printf( '<tr><th><label for="sr-holidays">休業日</label></th><td><textarea id="sr-holidays" name="%s" rows="4" cols="20">%s</textarea><p class="description">1行に1日、2026-12-31 の形で書きます。</p></td></tr>', $name( 'holidays' ), esc_textarea( implode( "\n", $s->holidays ) ) );
		printf( '<tr><th><label for="sr-mail">通知先のメール</label></th><td><input id="sr-mail" type="email" class="regular-text" name="%s" value="%s"><p class="description">空欄ならサイトの管理者に送ります。</p></td></tr>', $name( 'notify_email' ), esc_attr( $s->notify_email ) );
		echo '</table><p class="description">予約フォームは、固定ページなどに <code>[simple_reservation]</code> と書くと表示されます。</p>';
		submit_button();
		echo '</form></div>';
	}
}
