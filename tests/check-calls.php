<?php
/**
 * Static check: every `Class::method(` call and `array( Class::class, 'method' )` callback in the plugin
 * must point to a class and a method that exist. Usage: php tests/check-calls.php
 *
 * @package EmailLeadsTracker
 */

// phpcs:ignoreFile

require __DIR__ . '/bootstrap.php';

$root   = dirname( __DIR__ );
$files  = array_merge( array( $root . '/email-leads-tracker.php' ), glob_recursive( $root . '/includes' ) );
$errors = array();
$checks = 0;

foreach ( $files as $file ) {
	$code      = file_get_contents( $file );
	$namespace = preg_match( '/^namespace\s+([^;]+);/m', $code, $m ) ? $m[1] : '';
	$imports   = array();
	if ( preg_match_all( '/^use\s+([^;]+);/m', $code, $uses ) ) {
		foreach ( $uses[1] as $use ) {
			$parts                    = explode( '\\', trim( $use ) );
			$imports[ end( $parts ) ] = ltrim( trim( $use ), '\\' );
		}
	}

	$resolve = static function ( string $name ) use ( $namespace, $imports ): ?string {
		if ( in_array( $name, array( 'self', 'static', 'parent' ), true ) ) {
			return null;
		}
		if ( '\\' === $name[0] ) {
			return ltrim( $name, '\\' );
		}
		$first = explode( '\\', $name )[0];
		if ( isset( $imports[ $first ] ) ) {
			return $imports[ $first ] . substr( $name, strlen( $first ) );
		}
		return ( $namespace ? $namespace . '\\' : '' ) . $name;
	};

	// Class::method(
	preg_match_all( '/(\\\\?[A-Z][A-Za-z_\\\\]*)::([a-z_][A-Za-z0-9_]*)\s*\(/', $code, $calls, PREG_SET_ORDER );
	// array( Class::class, 'method' )
	preg_match_all( "/array\(\s*(\\\\?[A-Z][A-Za-z_\\\\]*)::class\s*,\s*'([A-Za-z0-9_]+)'\s*\)/", $code, $callbacks, PREG_SET_ORDER );

	foreach ( array_merge( $calls, $callbacks ) as $call ) {
		$class = $resolve( $call[1] );
		if ( null === $class || 0 === strpos( $class, 'WP_CLI' ) ) {
			continue;
		}
		++$checks;
		if ( 0 !== strpos( $class, 'EmailLeadsTracker\\' ) ) {
			continue; // WordPress or PHP classes.
		}
		if ( ! class_exists( $class ) ) {
			$errors[] = basename( $file ) . ": class {$class} does not exist";
			continue;
		}
		if ( ! method_exists( $class, $call[2] ) ) {
			$errors[] = basename( $file ) . ": method {$class}::{$call[2]} does not exist";
		}
	}
}

function glob_recursive( string $dir ): array {
	$files = array();
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() && 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
	return $files;
}

foreach ( $errors as $error ) {
	fwrite( STDERR, $error . "\n" );
}
printf( "%d calls checked, %d problems\n", $checks, count( $errors ) );
exit( $errors ? 1 : 0 );
