<?php
/**
 * Rezultatul normalizat al unei cereri de trimitere.
 *
 * Categorii (ARCHITECTURE.md §7.3):
 * - accepted  — Postmark a acceptat mesajul;
 * - temporary — se reîncearcă după backoff;
 * - ambiguous — cererea poate să fi ajuns; se verifică înainte de retrimitere;
 * - permanent — problema ține doar de acest lead;
 * - config    — problema afectează toate leadurile (alertă imediată).
 *
 * @package LocationLeadTracker
 */

namespace LocationLeadTracker\Postmark;

defined( 'ABSPATH' ) || exit;

final class SendResult {

	const ACCEPTED  = 'accepted';
	const TEMPORARY = 'temporary';
	const AMBIGUOUS = 'ambiguous';
	const PERMANENT = 'permanent';
	const CONFIG    = 'config';

	/** @var string */
	public $category;
	/** @var string */
	public $message_id = '';
	/** @var string */
	public $code = '';
	/** @var string */
	public $message = '';
	/** @var int Secunde, din headerul Retry-After. */
	public $retry_after = 0;

	private function __construct( string $category ) {
		$this->category = $category;
	}

	public static function accepted( string $message_id ): self {
		$result             = new self( self::ACCEPTED );
		$result->message_id = $message_id;
		return $result;
	}

	public static function failure( string $category, string $code, string $message, int $retry_after = 0 ): self {
		$result              = new self( $category );
		$result->code        = $code;
		$result->message     = $message;
		$result->retry_after = max( 0, $retry_after );
		return $result;
	}

	public function is( string $category ): bool {
		return $this->category === $category;
	}

	/**
	 * Eșec final pentru acest lead (permanent sau de configurare).
	 */
	public function is_final_failure(): bool {
		return self::PERMANENT === $this->category || self::CONFIG === $this->category;
	}
}
