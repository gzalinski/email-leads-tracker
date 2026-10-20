<?php
/**
 * Test runner. Usage: php tests/run.php
 *
 * @package LocationLeadTracker
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

use LocationLeadTracker\Http\ClientIp;
use LocationLeadTracker\Settings\Settings;

$GLOBALS['llt_passed'] = 0;
$GLOBALS['llt_failed'] = array();

function check( string $name, bool $condition, string $detail = '' ): void {
	if ( $condition ) {
		++$GLOBALS['llt_passed'];
	} else {
		$GLOBALS['llt_failed'][] = $name . ( $detail ? " — {$detail}" : '' );
	}
}

function same( string $name, $expected, $actual ): void {
	check( $name, $expected === $actual, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

function reset_settings( array $values = array() ): void {
	$GLOBALS['llt_test_options']['llt_settings'] = $values;
	Settings::flush();
	ClientIp::reset();
}

$test_files = glob( __DIR__ . '/cases/*.php' );
sort( $test_files );
foreach ( $test_files as $file ) {
	reset_settings();
	require $file;
}

$total = $GLOBALS['llt_passed'] + count( $GLOBALS['llt_failed'] );
foreach ( $GLOBALS['llt_failed'] as $failure ) {
	fwrite( STDERR, "FAILED: {$failure}\n" );
}
printf( "%d / %d checks passed\n", $GLOBALS['llt_passed'], $total );
exit( $GLOBALS['llt_failed'] ? 1 : 0 );
