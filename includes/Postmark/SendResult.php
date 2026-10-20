<?php
/**
 * The normalized result of a send request.
 *
 * Categories (ARCHITECTURE.md §7.3):
 * - accepted  — Postmark accepted the message;
 * - temporary — retried after a backoff;
 * - ambiguous — the request may have reached Postmark; checked before resending;
 * - permanent — the problem concerns only this lead;
 * - config    — the problem affects all leads (immediate alert).
 *
 * @package EnspireEmailLeadsTracker
 */

namespace EnspireEmailLeadsTracker\Postmark;

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
	/** @var int Seconds, from the Retry-After header. */
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
	 * Final failure for this lead (permanent or configuration).
	 */
	public function is_final_failure(): bool {
		return self::PERMANENT === $this->category || self::CONFIG === $this->category;
	}
}
