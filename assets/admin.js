/**
 * Email Leads Tracker — settings page and failed leads list.
 */
( function () {
	'use strict';

	var config = window.lltAdmin || {};

	// "Copy" buttons: <button data-llt-copy="#element-id">.
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
			button.textContent = 'Copied';
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

	// Confirmation for delete links: <a data-llt-confirm="Message">.
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '[data-llt-confirm]' );
		if ( link && ! window.confirm( link.getAttribute( 'data-llt-confirm' ) ) ) {
			event.preventDefault();
		}
	} );

	var form = document.getElementById( 'llt-leads-form' );
	if ( ! form ) {
		return;
	}

	var banner = form.querySelector( '.llt-select-all' );
	var allInput = document.getElementById( 'llt-all-matching' );
	var total = parseInt( form.getAttribute( 'data-total' ), 10 ) || 0;

	function rowChecks() {
		return Array.prototype.slice.call( form.querySelectorAll( '.llt-row-check' ) );
	}

	function resetAllMatching() {
		allInput.value = '';
		if ( banner ) {
			banner.querySelector( '.llt-select-all-text' ).textContent = 'All leads on this page are selected.';
			var link = banner.querySelector( '.llt-select-all-link' );
			link.hidden = false;
		}
	}

	// "Select all N leads matching the filter": shown when the whole page is checked.
	form.addEventListener( 'change', function () {
		var checks = rowChecks();
		var allChecked = checks.length > 0 && checks.every( function ( c ) {
			return c.checked;
		} );
		if ( ! banner ) {
			return;
		}
		if ( allChecked && total > checks.length ) {
			banner.hidden = false;
		} else {
			banner.hidden = true;
			resetAllMatching();
		}
	} );

	if ( banner ) {
		banner.querySelector( '.llt-select-all-link' ).addEventListener( 'click', function ( event ) {
			event.preventDefault();
			allInput.value = '1';
			banner.querySelector( '.llt-select-all-text' ).textContent = 'All ' + total + ' leads matching the filter are selected.';
			this.hidden = true;
		} );
	}

	// Confirmation for bulk deletion.
	form.addEventListener( 'submit', function ( event ) {
		var top = form.querySelector( 'select[name="llt_do"]' );
		var bottom = form.querySelector( 'select[name="llt_do_bottom"]' );
		var action = ( top && top.value ) || ( bottom && bottom.value ) || '';
		if ( ! action ) {
			event.preventDefault();
			window.alert( 'Choose an action.' );
			return;
		}
		var count = allInput.value ? total : rowChecks().filter( function ( c ) {
			return c.checked;
		} ).length;
		if ( ! count ) {
			event.preventDefault();
			window.alert( 'Select at least one lead.' );
			return;
		}
		if ( 'delete_content' === action && ! window.confirm( 'Permanently delete the visitors\' data for ' + count + ' leads? They can no longer be resent.' ) ) {
			event.preventDefault();
		}
	} );

	// "View message": the visitor's data is loaded on request.
	var dialog = document.getElementById( 'llt-message-dialog' );
	document.addEventListener( 'click', function ( event ) {
		var link = event.target.closest && event.target.closest( '.llt-view-message' );
		if ( ! link || ! dialog ) {
			return;
		}
		event.preventDefault();
		var body = dialog.querySelector( '.llt-dialog-body' );
		body.textContent = 'Loading…';
		if ( dialog.showModal ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}

		var data = new FormData();
		data.append( 'action', 'llt_lead_details' );
		data.append( 'nonce', config.nonce || '' );
		data.append( 'lead', link.getAttribute( 'data-lead' ) );

		fetch( config.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				body.textContent = '';
				if ( ! result.success ) {
					body.textContent = ( result.data && result.data.message ) || 'The message could not be loaded.';
					return;
				}
				var d = result.data;
				var list = document.createElement( 'dl' );
				[
					[ 'Lead', '#' + d.lead ],
					[ 'Date', d.date ],
					[ 'Name', d.name ],
					[ 'Email', d.email ],
					[ 'Phone', d.phone || '—' ],
				].forEach( function ( pair ) {
					var dt = document.createElement( 'dt' );
					var dd = document.createElement( 'dd' );
					dt.textContent = pair[ 0 ];
					dd.textContent = pair[ 1 ];
					list.appendChild( dt );
					list.appendChild( dd );
				} );
				var message = document.createElement( 'pre' );
				message.className = 'llt-dialog-message';
				message.textContent = d.message;
				body.appendChild( list );
				body.appendChild( message );
			} )
			.catch( function () {
				body.textContent = 'The message could not be loaded.';
			} );
	} );
} )();
