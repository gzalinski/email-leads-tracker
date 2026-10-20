<?php
// phpcs:ignoreFile
use EmailLeadsTracker\Postmark\ErrorClassifier;
use EmailLeadsTracker\Postmark\SendResult;

$r = ErrorClassifier::from_response( 200, '{"ErrorCode":0,"Message":"OK","MessageID":"abc-123"}' );
same( '200 accepted', SendResult::ACCEPTED, $r->category );
same( '200 MessageID', 'abc-123', $r->message_id );

same( '200 without MessageID', SendResult::AMBIGUOUS, ErrorClassifier::from_response( 200, '{}' )->category );

$cases = array(
	array( 401, '{"ErrorCode":10,"Message":"Bad token"}', SendResult::CONFIG, 'pm_10' ),
	array( 401, '', SendResult::CONFIG, 'pm_10' ),
	array( 422, '{"ErrorCode":412,"Message":"Pending approval"}', SendResult::CONFIG, 'pm_412' ),
	array( 422, '{"ErrorCode":413,"Message":"Not approved"}', SendResult::CONFIG, 'pm_413' ),
	array( 422, '{"ErrorCode":403,"Message":"Invalid From"}', SendResult::CONFIG, 'pm_403' ),
	array( 422, '{"ErrorCode":400,"Message":"Sender signature"}', SendResult::CONFIG, 'pm_400' ),
	array( 422, '{"ErrorCode":1235,"Message":"Stream"}', SendResult::CONFIG, 'pm_1235' ),
	array( 422, '{"ErrorCode":1480,"Message":"IP"}', SendResult::CONFIG, 'pm_1480' ),
	array( 422, '{"ErrorCode":300,"Message":"Invalid email"}', SendResult::PERMANENT, 'pm_300' ),
	array( 422, '{"ErrorCode":406,"Message":"Inactive recipient"}', SendResult::PERMANENT, 'pm_406' ),
	array( 422, '{"ErrorCode":999,"Message":"?"}', SendResult::PERMANENT, 'pm_999' ),
	array( 429, '', SendResult::TEMPORARY, 'http_429' ),
	array( 500, '{"ErrorCode":101,"Message":"Internal"}', SendResult::TEMPORARY, 'pm_101' ),
	array( 503, '{"ErrorCode":100,"Message":"Maintenance"}', SendResult::TEMPORARY, 'pm_100' ),
	array( 502, '<html>', SendResult::TEMPORARY, 'http_502' ),
	array( 413, '', SendResult::PERMANENT, 'http_413' ),
	array( 418, '', SendResult::PERMANENT, 'http_418' ),
);
foreach ( $cases as $case ) {
	$result = ErrorClassifier::from_response( $case[0], $case[1] );
	same( "HTTP {$case[0]} {$case[3]} category", $case[2], $result->category );
	same( "HTTP {$case[0]} {$case[3]} code", $case[3], $result->code );
}

same( 'Retry-After in seconds', 120, ErrorClassifier::from_response( 429, '', '120' )->retry_after );
check( 'Retry-After as a date', ErrorClassifier::retry_after( gmdate( 'D, d M Y H:i:s', time() + 300 ) . ' GMT' ) >= 298 );
same( 'invalid Retry-After', 0, ErrorClassifier::retry_after( 'tomorrow-ish' ) );

$transport = array(
	'cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received' => SendResult::AMBIGUOUS,
	'cURL error 28: Connection timed out after 5001 milliseconds'                       => SendResult::TEMPORARY,
	'cURL error 28: Resolving timed out after 5000 milliseconds'                        => SendResult::TEMPORARY,
	'cURL error 6: Could not resolve host: api.postmarkapp.com'                          => SendResult::TEMPORARY,
	'cURL error 7: Failed to connect to api.postmarkapp.com port 443'                   => SendResult::TEMPORARY,
	'cURL error 35: OpenSSL SSL_connect: SSL_ERROR_SYSCALL'                               => SendResult::TEMPORARY,
	'cURL error 52: Empty reply from server'                                             => SendResult::AMBIGUOUS,
	'cURL error 56: Recv failure: Connection reset by peer'                             => SendResult::AMBIGUOUS,
	'A valid URL was not provided.'                                                      => SendResult::AMBIGUOUS,
);
foreach ( $transport as $message => $expected ) {
	same( 'transport: ' . substr( $message, 0, 40 ), $expected, ErrorClassifier::from_transport_error( new WP_Error( 'http_request_failed', $message ) )->category );
}

check( 'description of 406', false !== strpos( ErrorClassifier::describe( 'pm_406' ), 'inactive' ) );
check( 'description of an unknown code', false !== strpos( ErrorClassifier::describe( 'pm_12345' ), 'pm_12345' ) );
check( 'final failure', SendResult::failure( SendResult::CONFIG, 'x', '' )->is_final_failure() && ! SendResult::failure( SendResult::AMBIGUOUS, 'x', '' )->is_final_failure() );
