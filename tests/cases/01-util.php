<?php
// phpcs:ignoreFile
use LocationLeadTracker\Util;

same( 'iso 7 zecimale', '2026-11-05 16:33:54', Util::iso_to_mysql( '2026-11-05T16:33:54.9070259Z' ) );
same( 'iso cu fus orar', '2014-02-14 16:13:10', Util::iso_to_mysql( '2014-02-14T11:13:10.8054242-05:00' ) );
same( 'iso gol', null, Util::iso_to_mysql( '' ) );
same( 'iso invalid', null, Util::iso_to_mysql( 'nu e dată' ) );
same( 'mask emails', 'Adresa *** este inactivă', Util::mask_emails( 'Adresa john.doe@yahoo.com este inactivă' ) );
same( 'single line', 'a b', Util::single_line( "a\r\nb" ) );
check( 'error_text max 255', strlen( Util::error_text( str_repeat( 'x', 400 ) ) ) === 255 );
same( 'ids', array( 3, 1 ), Util::ids( array( '3', 0, -2, 'x', 1, 3 ) ) );
