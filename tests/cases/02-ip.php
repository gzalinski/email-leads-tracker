<?php
// phpcs:ignoreFile
use EmailLeadsTracker\Http\Cidr;
use EmailLeadsTracker\Http\ClientIp;
use EmailLeadsTracker\Http\ProxyRanges;

// CIDR.
check( 'cidr v4 inside the network', Cidr::contains( '104.16.0.0/13', '104.23.255.1' ) );
check( 'cidr v4 outside the network', ! Cidr::contains( '104.16.0.0/13', '104.24.0.1' ) );
check( 'cidr v4 /22', Cidr::contains( '103.21.244.0/22', '103.21.247.255' ) );
check( 'cidr v6', Cidr::contains( '2606:4700::/32', '2606:4700:10::1' ) );
check( 'cidr v6 outside', ! Cidr::contains( '2606:4700::/32', '2606:4701::1' ) );
check( 'cidr different families', ! Cidr::contains( '104.16.0.0/13', '2606:4700::1' ) );
check( 'cidr without mask', Cidr::contains( '10.1.2.3', '10.1.2.3' ) );
check( 'cidr valid', Cidr::is_valid( '2a06:98c0::/29' ) && Cidr::is_valid( '10.0.0.0/8' ) );
check( 'cidr invalid', ! Cidr::is_valid( '10.0.0.0/33' ) && ! Cidr::is_valid( 'abc/8' ) && ! Cidr::is_valid( '10.0.0.0/x' ) );

// Cloudflare list.
check( 'Cloudflare IP', ProxyRanges::is_cloudflare( '172.70.1.1' ) );
check( 'non-Cloudflare IP', ! ProxyRanges::is_cloudflare( '8.8.8.8' ) );
same( 'parse list', array( '1.2.3.0/24', '2400:cb00::/32' ), ProxyRanges::parse( "1.2.3.0/24\n\n2400:cb00::/32\ngarbage\n" ) );

$visitor = '85.204.10.20';
$cases   = array(
	'direct'                           => array( array( 'REMOTE_ADDR' => $visitor ), 'auto', array(), array( $visitor, 'direct' ) ),
	'direct ignores forged headers'    => array( array( 'REMOTE_ADDR' => $visitor, 'HTTP_CF_CONNECTING_IP' => '1.1.1.1', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1' ), 'auto', array(), array( $visitor, 'direct' ) ),
	'cloudflare'                       => array( array( 'REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => $visitor ), 'auto', array(), array( $visitor, 'cloudflare' ) ),
	'cloudflare without header'        => array( array( 'REMOTE_ADDR' => '172.70.1.1' ), 'auto', array(), array( '172.70.1.1', 'cloudflare' ) ),
	'cloudflare invalid header'        => array( array( 'REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => 'abc' ), 'auto', array(), array( '172.70.1.1', 'cloudflare' ) ),
	'local nginx'                      => array( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => $visitor ), 'auto', array(), array( $visitor, 'proxy' ) ),
	'docker with chain'                => array( array( 'REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, ' . $visitor . ', 10.0.0.5' ), 'auto', array(), array( $visitor, 'proxy' ) ),
	'cloudflare + local nginx'         => array( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => $visitor . ', 172.70.1.1' ), 'auto', array(), array( $visitor, 'proxy' ) ),
	'local proxy without headers'      => array( array( 'REMOTE_ADDR' => '127.0.0.1' ), 'auto', array(), array( '127.0.0.1', 'proxy' ) ),
	'local proxy x-real-ip'            => array( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => $visitor ), 'auto', array(), array( $visitor, 'proxy' ) ),
	'additional proxy'                 => array( array( 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => $visitor ), 'auto', array( '203.0.113.0/24' ), array( $visitor, 'proxy' ) ),
	'undeclared public proxy'          => array( array( 'REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_FOR' => $visitor ), 'auto', array(), array( '203.0.113.7', 'direct' ) ),
	'direct mode ignores Cloudflare'   => array( array( 'REMOTE_ADDR' => '172.70.1.1', 'HTTP_CF_CONNECTING_IP' => $visitor ), 'direct', array(), array( '172.70.1.1', 'direct' ) ),
	'cloudflare mode ignores proxies'  => array( array( 'REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => $visitor ), 'cloudflare', array(), array( '127.0.0.1', 'direct' ) ),
	'missing REMOTE_ADDR'              => array( array(), 'auto', array(), array( '0.0.0.0', 'unknown' ) ),
	'IPv6 direct'                      => array( array( 'REMOTE_ADDR' => '2a02:2f0e::1' ), 'auto', array(), array( '2a02:2f0e::1', 'direct' ) ),
	'IPv6 Cloudflare'                  => array( array( 'REMOTE_ADDR' => '2606:4700:10::1', 'HTTP_CF_CONNECTING_IP' => '2a02:2f0e::1' ), 'auto', array(), array( '2a02:2f0e::1', 'cloudflare' ) ),
);
foreach ( $cases as $name => $case ) {
	same( 'ClientIp: ' . $name, $case[3], ClientIp::resolve( $case[0], $case[1], $case[2] ) );
}
