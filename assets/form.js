/**
 * Location Lead Tracker — submits the form via AJAX.
 * Without JavaScript the form still works (redirect back to the page with the message).
 */
( function () {
	'use strict';

	function setStatus( form, ok, message ) {
		var box = form.querySelector( '.llt-status' );
		if ( ! box ) {
			return;
		}
		box.textContent = message || '';
		box.classList.toggle( 'llt-status--ok', !! ok );
		box.classList.toggle( 'llt-status--error', ! ok );
	}

	function clearErrors( form ) {
		form.querySelectorAll( '.llt-error' ).forEach( function ( el ) {
			el.textContent = '';
		} );
		form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
			el.removeAttribute( 'aria-invalid' );
		} );
	}

	function showErrors( form, errors ) {
		var first = null;
		Object.keys( errors || {} ).forEach( function ( name ) {
			var slot = form.querySelector( '.llt-error[data-for="' + name + '"]' );
			var input = form.querySelector( '[name="' + name + '"]' );
			if ( slot ) {
				slot.textContent = errors[ name ];
			}
			if ( input ) {
				input.setAttribute( 'aria-invalid', 'true' );
				first = first || input;
			}
		} );
		if ( first ) {
			first.focus();
		}
	}

	function resetTurnstile( form ) {
		var widget = form.querySelector( '.cf-turnstile' );
		if ( widget && window.turnstile ) {
			try {
				window.turnstile.reset( widget );
			} catch ( e ) {}
		}
	}

	function onSubmit( event ) {
		var form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'llt-form' ) || ! window.fetch || ! window.FormData ) {
			return;
		}
		event.preventDefault();

		var button = form.querySelector( '.llt-submit' );
		if ( button && button.disabled ) {
			return;
		}
		if ( button ) {
			button.disabled = true;
		}
		form.setAttribute( 'aria-busy', 'true' );
		clearErrors( form );

		fetch( form.action, {
			method: 'POST',
			body: new FormData( form ),
			credentials: 'same-origin',
			headers: { 'X-LLT-Ajax': '1', Accept: 'application/json' },
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				setStatus( form, data.ok, data.message );
				if ( data.ok ) {
					form.reset();
				} else {
					showErrors( form, data.errors );
				}
			} )
			.catch( function () {
				setStatus( form, false, 'A connection error occurred. Please try again.' );
			} )
			.then( function () {
				if ( button ) {
					button.disabled = false;
				}
				form.removeAttribute( 'aria-busy' );
				resetTurnstile( form );
			} );
	}

	document.addEventListener( 'submit', onSubmit );
} )();
