<?php
/**
 * プラグインを削除したときに、予約のテーブルと設定を消す（個人情報を残さないため）。
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require __DIR__ . '/src/Settings.php';
require __DIR__ . '/src/Repository.php';

SimpleReservation\Repository::uninstall();
delete_option( SimpleReservation\Settings::OPTION );
