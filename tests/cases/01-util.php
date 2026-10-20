<?php
// phpcs:ignoreFile
use EmailLeadsTracker\Util;

same( 'iso with 7 decimals', '2026-11-05 16:33:54', Util::iso_to_mysql( '2026-11-05T16:33:54.9070259Z' ) );
same( 'iso with time zone', '2014-02-14 16:13:10', Util::iso_to_mysql( '2014-02-14T11:13:10.8054242-05:00' ) );
same( 'empty iso', null, Util::iso_to_mysql( '' ) );
same( 'invalid iso', null, Util::iso_to_mysql( 'not a date' ) );
same( 'mask emails', 'Address *** is inactive', Util::mask_emails( 'Address john.doe@yahoo.com is inactive' ) );
same( 'single line', 'a b', Util::single_line( "a\r\nb" ) );
check( 'error_text at most 255', strlen( Util::error_text( str_repeat( 'x', 400 ) ) ) === 255 );
same( 'ids', array( 3, 1 ), Util::ids( array( '3', 0, -2, 'x', 1, 3 ) ) );
