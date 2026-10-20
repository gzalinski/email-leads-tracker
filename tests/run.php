<?php
/**
 * Teste pentru logica fără bază de date. Rulare: php tests/run.php
 *
 * @package LocationLeadTracker
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

use LocationLeadTracker\Form\Validator;
use LocationLeadTracker\Http\Cidr;
use LocationLeadTracker\Http\ClientIp;
use LocationLeadTracker\Http\ProxyRanges;
use LocationLeadTracker\Lead\LeadStatus;
use LocationLeadTracker\Postmark\EmailBuilder;
use LocationLeadTracker\Postmark\ErrorClassifier;
use LocationLeadTracker\Postmark\SendResult;
use LocationLeadTracker\Queue\BackoffPolicy;
use LocationLeadTracker\Settings\SecretStore;
use LocationLeadTracker\Settings\Settings;
use LocationLeadTracker\Settings\SettingsSanitizer;
use LocationLeadTracker\Shortcode\ContextSigner;
use LocationLeadTracker\Shortcode\RecipientResolver;
use LocationLeadTracker\Util;

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
	check( $name, $expected === $actual, 'așteptat ' . var_export( $expected, true ) . ', primit ' . var_export( $actual, true ) );
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
	fwrite( STDERR, "EȘUAT: {$failure}\n" );
}
printf( "%d / %d verificări trecute\n", $GLOBALS['llt_passed'], $total );
exit( $GLOBALS['llt_failed'] ? 1 : 0 );
