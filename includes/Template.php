<?php
/**
 * Template rendering, with theme overrides.
 *
 * A theme can override a template by copying it to `email-leads-tracker/<file>`.
 *
 * @package EmailLeadsTracker
 */

namespace EmailLeadsTracker;

defined( 'ABSPATH' ) || exit;

final class Template {

	/**
	 * @param string $name File name inside `templates/`.
	 * @param array  $llt  Variables available in the template as `$llt`.
	 */
	public static function render( string $name, array $llt ): string {
		$name  = basename( $name );
		$theme = locate_template( 'email-leads-tracker/' . $name );
		$file  = apply_filters( 'llt_form_template', $theme ? $theme : LLT_DIR . 'templates/' . $name, $name );

		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
