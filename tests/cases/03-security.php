<?php
// phpcs:ignoreFile
use EmailLeadsTracker\Settings\SecretStore;
use EmailLeadsTracker\Shortcode\ContextSigner;

// Signed context.
$signed   = ContextSigner::sign( 245, 1700000000 );
$verified = ContextSigner::verify( $signed['ctx'], $signed['sig'] );
same( 'valid context', array( 'object_id' => 245, 'rendered_at' => 1700000000 ), $verified );

$forged = ContextSigner::sign( 999, 1700000000 );
same( 'context: changed object_id', null, ContextSigner::verify( $forged['ctx'], $signed['sig'] ) );
same( 'context: wrong signature', null, ContextSigner::verify( $signed['ctx'], str_repeat( 'a', 64 ) ) );
same( 'context: empty', null, ContextSigner::verify( '', '' ) );
$tampered = rtrim( strtr( base64_encode( json_encode( array( 'o' => 245, 't' => 1 ) ) ), '+/', '-_' ), '=' );
same( 'context: forged time', null, ContextSigner::verify( $tampered, $signed['sig'] ) );

// Secret encryption.
$encrypted = SecretStore::encrypt( 'pm-token-123' );
check( 'encrypted secret does not contain the text', false === strpos( $encrypted, 'pm-token-123' ) );
same( 'decrypted secret', 'pm-token-123', SecretStore::decrypt( $encrypted ) );
check( 'different ciphertexts (nonce)', SecretStore::encrypt( 'x' ) !== SecretStore::encrypt( 'x' ) );
same( 'corrupted secret', null, SecretStore::decrypt( 'v1:abc' ) );
same( 'secret without prefix', null, SecretStore::decrypt( 'pm-token-123' ) );
$GLOBALS['llt_test_salt'] = 'salt-two';
same( 'secret after the salts changed', null, SecretStore::decrypt( $encrypted ) );
$GLOBALS['llt_test_salt'] = 'salt-one';
same( 'secret with the original salts', 'pm-token-123', SecretStore::decrypt( $encrypted ) );
