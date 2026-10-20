<?php
/**
 * Randarea template-urilor, cu posibilitate de override din temă.
 *
 * Tema poate suprascrie un template copiindu-l în `location-lead-tracker/<fișier>`.
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker;

defined( 'ABSPATH' ) || exit;

final class Template {

	/**
	 * @param string $name Numele fișierului din `templates/`.
	 * @param array  $llt  Variabilele disponibile în template ca `$llt`.
	 */
	public static function render( string $name, array $llt ): string {
		$name  = basename( $name );
		$theme = locate_template( 'location-lead-tracker/' . $name );
		$file  = apply_filters( 'llt_form_template', $theme ? $theme : LLT_DIR . 'templates/' . $name, $name );

		if ( ! is_readable( $file ) ) {
			return '';
		}
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
