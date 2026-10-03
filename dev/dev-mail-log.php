<?php
/**
 * 開発用：メールを送らずに wp-content/mail-log.jsonl に書き出す（この開発環境だけに置く。プラグインには含めない）
 */
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		$line = wp_json_encode( array( 'time' => current_time( 'mysql' ), 'to' => $atts['to'], 'subject' => $atts['subject'], 'message' => $atts['message'] ), JSON_UNESCAPED_UNICODE );
		file_put_contents( WP_CONTENT_DIR . '/mail-log.jsonl', $line . "\n", FILE_APPEND | LOCK_EX );
		return true;
	},
	10,
	2
);
