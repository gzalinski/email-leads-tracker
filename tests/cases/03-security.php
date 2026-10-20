<?php
// phpcs:ignoreFile
use LocationLeadTracker\Settings\SecretStore;
use LocationLeadTracker\Shortcode\ContextSigner;

// Context semnat.
$signed   = ContextSigner::sign( 245, 1700000000 );
$verified = ContextSigner::verify( $signed['ctx'], $signed['sig'] );
same( 'context valid', array( 'object_id' => 245, 'rendered_at' => 1700000000 ), $verified );

$forged = ContextSigner::sign( 999, 1700000000 );
same( 'context: object_id schimbat', null, ContextSigner::verify( $forged['ctx'], $signed['sig'] ) );
same( 'context: semnătură greșită', null, ContextSigner::verify( $signed['ctx'], str_repeat( 'a', 64 ) ) );
same( 'context: gol', null, ContextSigner::verify( '', '' ) );
$tampered = rtrim( strtr( base64_encode( json_encode( array( 'o' => 245, 't' => 1 ) ) ), '+/', '-_' ), '=' );
same( 'context: timp falsificat', null, ContextSigner::verify( $tampered, $signed['sig'] ) );

// Criptarea secretelor.
$encrypted = SecretStore::encrypt( 'pm-token-123' );
check( 'secret criptat nu conține textul', false === strpos( $encrypted, 'pm-token-123' ) );
same( 'secret decriptat', 'pm-token-123', SecretStore::decrypt( $encrypted ) );
check( 'criptări diferite (nonce)', SecretStore::encrypt( 'x' ) !== SecretStore::encrypt( 'x' ) );
same( 'secret corupt', null, SecretStore::decrypt( 'v1:abc' ) );
same( 'secret fără prefix', null, SecretStore::decrypt( 'pm-token-123' ) );
$GLOBALS['llt_test_salt'] = 'salt-two';
same( 'secret după schimbarea salturilor', null, SecretStore::decrypt( $encrypted ) );
$GLOBALS['llt_test_salt'] = 'salt-one';
same( 'secret cu salturile inițiale', 'pm-token-123', SecretStore::decrypt( $encrypted ) );
