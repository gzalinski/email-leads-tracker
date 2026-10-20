<?php
/**
 * Test runner. Usage: php tests/run.php
 *
 * @package EnspireEmailLeadsTracker
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

use EnspireEmailLeadsTracker\Http\ClientIp;
use EnspireEmailLeadsTracker\Settings\Settings;

$GLOBALS['eelt_passed'] = 0;
$GLOBALS['eelt_failed'] = array();

function check( string $name, bool $condition, string $detail = '' ): void {
	if ( $condition ) {
		++$GLOBALS['eelt_passed'];
	} else {
		$GLOBALS['eelt_failed'][] = $name . ( $detail ? " — {$detail}" : '' );
	}
}

function same( string $name, $expected, $actual ): void {
	check( $name, $expected === $actual, 'expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
}

function reset_settings( array $values = array() ): void {
	$GLOBALS['eelt_test_options']['eelt_settings'] = $values;
	Settings::flush();
	ClientIp::reset();
}

$test_files = glob( __DIR__ . '/cases/*.php' );
sort( $test_files );
foreach ( $test_files as $file ) {
	reset_settings();
	require $file;
}

$total = $GLOBALS['eelt_passed'] + count( $GLOBALS['eelt_failed'] );
foreach ( $GLOBALS['eelt_failed'] as $failure ) {
	fwrite( STDERR, "FAILED: {$failure}\n" );
}
printf( "%d / %d checks passed\n", $GLOBALS['eelt_passed'], $total );
exit( $GLOBALS['eelt_failed'] ? 1 : 0 );
