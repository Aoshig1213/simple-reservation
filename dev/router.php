<?php
// PHP の組み込みサーバー用：実在するファイルはそのまま返し、それ以外は WordPress に渡す（パーマリンク用）
$root = getenv( 'WP_ROOT' ) ?: __DIR__ . '/site';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( $root . $path ) ) {
	return false;
}
if ( is_dir( $root . $path ) && is_file( $root . rtrim( $path, '/' ) . '/index.php' ) ) {
	$_SERVER['SCRIPT_NAME'] = rtrim( $path, '/' ) . '/index.php';
	chdir( $root . rtrim( $path, '/' ) );
	require $root . rtrim( $path, '/' ) . '/index.php';
	return;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
chdir( $root );
require $root . '/index.php';
