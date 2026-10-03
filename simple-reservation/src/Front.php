<?php
/**
 * 予約フォーム（ショートコード [simple_reservation]）と送信の受け付け。
 * 送信は admin-post.php で受け、結果を付けて元のページに戻す（再読み込みで二重送信しないように）。
 */

namespace SimpleReservation;

final class Front {
	private const ACTION = 'simple_reservation_book';

	public static function register(): void {
		add_shortcode( 'simple_reservation', array( self::class, 'render' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function assets(): void {
		wp_register_style( 'simple-reservation', plugins_url( 'assets/form.css', SR_PLUGIN_FILE ), array(), SR_VERSION );
		wp_register_script( 'simple-reservation', plugins_url( 'assets/form.js', SR_PLUGIN_FILE ), array(), SR_VERSION, true );
	}

	public static function render(): string {
		wp_enqueue_style( 'simple-reservation' );
		wp_enqueue_script( 'simple-reservation' );
		$s     = Plugin::settings();
		$cal   = Plugin::calendar( $s );
		$dates = $cal->bookable_dates();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 表示の切り替えだけで、データは変えない
		$date   = isset( $_GET['sr_date'] ) ? sanitize_text_field( wp_unslash( $_GET['sr_date'] ) ) : '';
		$result = isset( $_GET['sr_result'] ) ? sanitize_key( wp_unslash( $_GET['sr_result'] ) ) : '';
		$token  = isset( $_GET['sr_token'] ) ? sanitize_key( wp_unslash( $_GET['sr_token'] ) ) : '';
		// phpcs:enable
		if ( ! in_array( $date, $dates, true ) ) {
			$date = $dates[0] ?? '';
		}
		$flash = $token ? get_transient( 'sr_flash_' . $token ) : false;
		$old   = is_array( $flash ) ? $flash['old'] : array();
		$err   = is_array( $flash ) ? $flash['errors'] : array();

		ob_start();
		echo '<div class="sr" id="sr-form">';
		if ( 'done' === $result && is_array( $flash ) ) {
			printf(
				'<div class="sr-notice sr-ok" role="status"><strong>ご予約を受け付けました。</strong><br>%s　%d名<br>確認のメールを %s にお送りしました。</div>',
				esc_html( Plugin::format_slot( $flash['old']['date'], $flash['old']['time'] ) ),
				(int) $flash['old']['people'],
				esc_html( $flash['old']['email'] )
			);
			echo '</div>';
			return (string) ob_get_clean();
		}
		if ( 'full' === $result ) {
			echo '<div class="sr-notice sr-ng" role="alert">申し訳ありません。選ばれた枠は、ほかのご予約で満席になりました。別の枠をお選びください。</div>';
		} elseif ( $err ) {
			echo '<div class="sr-notice sr-ng" role="alert">入力内容をご確認ください。</div>';
		}
		if ( ! $dates ) {
			echo '<p>現在、予約を受け付けている日はありません。</p></div>';
			return (string) ob_get_clean();
		}

		// 日付を選ぶ（JavaScript がなくても、ボタンで切り替えられる）
		echo '<form method="get" action="' . esc_url( get_permalink() . '#sr-form' ) . '" class="sr-date">';
		foreach ( array_diff_key( $_GET, array_flip( array( 'sr_date', 'sr_result', 'sr_token' ) ) ) as $k => $v ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( is_string( $v ) ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( sanitize_key( $k ) ), esc_attr( sanitize_text_field( wp_unslash( $v ) ) ) );
			}
		}
		echo '<label for="sr-date">ご希望の日</label> <select id="sr-date" name="sr_date" data-autosubmit>';
		foreach ( $dates as $d ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $d ), selected( $d, $date, false ), esc_html( Plugin::format_slot( $d, '' ) ) );
		}
		echo '</select> <button type="submit" class="sr-noscript">表示</button></form>';

		$remaining = $cal->remaining( $date, Repository::booked_counts( $date ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sr-book" novalidate>';
		wp_nonce_field( self::ACTION, 'sr_nonce' );
		printf( '<input type="hidden" name="action" value="%s"><input type="hidden" name="date" value="%s"><input type="hidden" name="back" value="%s">', esc_attr( self::ACTION ), esc_attr( $date ), esc_url( get_permalink() ) );
		// 迷惑投稿よけ（人には見えない欄。入力があれば受け付けない）
		echo '<div class="sr-hp" aria-hidden="true"><label>空欄のまま<input type="text" name="sr_website" tabindex="-1" autocomplete="off"></label></div>';

		echo '<fieldset class="sr-slots"><legend>時間</legend>';
		foreach ( $remaining as $t => $left ) {
			$id = 'sr-t-' . str_replace( ':', '', $t );
			printf(
				'<label class="sr-slot%s" for="%s"><input type="radio" id="%s" name="time" value="%s"%s%s><span class="sr-time">%s</span><span class="sr-left">%s</span></label>',
				$left ? '' : ' sr-full',
				esc_attr( $id ),
				esc_attr( $id ),
				esc_attr( $t ),
				checked( $t, $old['time'] ?? '', false ),
				disabled( 0, $left, false ),
				esc_html( $t ),
				$left ? esc_html( "残り{$left}名" ) : '満席'
			);
		}
		echo '</fieldset>';
		self::error( $err, 'time' );

		echo '<p><label for="sr-people">人数</label><select id="sr-people" name="people">';
		for ( $i = 1; $i <= $s->max_people; $i++ ) {
			printf( '<option value="%d"%s>%d名</option>', $i, selected( $i, (int) ( $old['people'] ?? 1 ), false ), $i );
		}
		echo '</select></p>';
		self::error( $err, 'people' );
		self::field( 'name', 'お名前', 'text', $old, $err, 'name' );
		self::field( 'email', 'メールアドレス', 'email', $old, $err, 'email' );
		self::field( 'phone', '電話番号', 'tel', $old, $err, 'tel' );
		printf( '<p><label for="sr-note">ご要望（任意）</label><textarea id="sr-note" name="note" rows="3" maxlength="500">%s</textarea></p>', esc_textarea( $old['note'] ?? '' ) );
		self::error( $err, 'note' );
		echo '<p><button type="submit" class="sr-submit">この内容で予約する</button></p></form></div>';
		return (string) ob_get_clean();
	}

	private static function field( string $k, string $label, string $type, array $old, array $err, string $autocomplete ): void {
		printf(
			'<p><label for="sr-%1$s">%2$s</label><input id="sr-%1$s" type="%3$s" name="%1$s" value="%4$s" autocomplete="%5$s" required%6$s></p>',
			esc_attr( $k ),
			esc_html( $label ),
			esc_attr( $type ),
			esc_attr( $old[ $k ] ?? '' ),
			esc_attr( $autocomplete ),
			isset( $err[ $k ] ) ? ' aria-invalid="true"' : ''
		);
		self::error( $err, $k );
	}

	private static function error( array $err, string $k ): void {
		if ( isset( $err[ $k ] ) ) {
			printf( '<p class="sr-error">%s</p>', esc_html( $err[ $k ] ) );
		}
	}

	/** 送信の受け付け */
	public static function handle(): void {
		$back = isset( $_POST['back'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['back'] ) ), home_url( '/' ) ) : home_url( '/' );
		if ( ! isset( $_POST['sr_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sr_nonce'] ) ), self::ACTION ) ) {
			wp_die( esc_html( 'ページの有効期限が切れました。お手数ですが、もう一度お試しください。' ), '', array( 'response' => 403, 'back_link' => true ) );
		}
		if ( ! empty( $_POST['sr_website'] ) ) { // 迷惑投稿
			wp_safe_redirect( $back );
			exit;
		}
		$s   = Plugin::settings();
		$in  = array_map( fn( $v ) => is_string( $v ) ? wp_unslash( $v ) : '', array_intersect_key( $_POST, array_flip( array( 'date', 'time', 'people', 'name', 'email', 'phone', 'note' ) ) ) );
		list( $v, $errors ) = ( new Validator( $s, Plugin::calendar( $s ) ) )->validate( $in );

		$result = 'error';
		if ( ! $errors ) {
			// sanitize_email は形の正しくないアドレスを空にするので、入力チェックを通ってから使う（入力ミスの画面では入れた値のまま戻す）
			$id = Repository::book( array( 'email' => sanitize_email( $v['email'] ) ) + $v, $s->capacity );
			if ( is_int( $id ) ) {
				$result = 'done';
				Plugin::send_mails( Repository::get( $id ) );
			} else {
				$result = 'full' === $id ? 'full' : 'error';
				if ( 'full' !== $id ) {
					$errors['time'] = '混み合っているため受け付けられませんでした。少し待ってからもう一度お試しください';
				}
			}
		}
		// 結果と入力内容は短い間だけ一時保存し、ページに戻してから表示する
		$token = strtolower( wp_generate_password( 20, false ) ); // sanitize_key で読むので小文字だけにする
		set_transient( 'sr_flash_' . $token, array( 'old' => $v, 'errors' => $errors ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( array( 'sr_date' => $v['date'], 'sr_result' => $result, 'sr_token' => $token ), $back ) . '#sr-form' );
		exit;
	}
}
