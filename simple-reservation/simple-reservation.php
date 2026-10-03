<?php
/**
 * Plugin Name:       Simple Reservation（予約受付）
 * Description:       日付と時間枠を選んで予約するフォームと、管理画面での予約管理。定員を超える予約は受け付けません。フォームは [simple_reservation] で表示します。
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Aoshig1213
 * License:           GPL-2.0-or-later
 * Text Domain:       simple-reservation
 */

defined( 'ABSPATH' ) || exit;

const SR_VERSION     = '1.0.0';
const SR_PLUGIN_FILE = __FILE__;

// Composer を使わない環境でも動くように、クラスは自前で読み込む
spl_autoload_register(
	function ( $class ) {
		if ( str_starts_with( $class, 'SimpleReservation\\' ) ) {
			$file = __DIR__ . '/src/' . substr( $class, strlen( 'SimpleReservation\\' ) ) . '.php';
			if ( is_readable( $file ) ) {
				require $file;
			}
		}
	}
);

register_activation_hook(
	__FILE__,
	function () {
		SimpleReservation\Repository::install();
		add_option( SimpleReservation\Settings::OPTION, ( new SimpleReservation\Settings() )->to_array() );
	}
);

SimpleReservation\Front::register();
if ( is_admin() ) {
	SimpleReservation\Admin::register();
}
