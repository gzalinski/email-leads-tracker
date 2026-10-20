/**
 * Location Lead Tracker — pagina de setări.
 */
( function () {
	'use strict';

	// Butoanele „Copiază”: <button data-llt-copy="#id-element">.
	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest && event.target.closest( '[data-llt-copy]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		var source = document.querySelector( button.getAttribute( 'data-llt-copy' ) );
		if ( ! source ) {
			return;
		}
		var text = source.value || source.textContent || '';
		var done = function () {
			var label = button.textContent;
			button.textContent = 'Copiat';
			setTimeout( function () {
				button.textContent = label;
			}, 1500 );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var range = document.createRange();
			range.selectNodeContents( source );
			var selection = window.getSelection();
			selection.removeAllRanges();
			selection.addRange( range );
			document.execCommand( 'copy' );
			done();
		}
	} );
} )();
