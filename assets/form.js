/**
 * Enspire Email Leads Tracker — submits the form via AJAX.
 * Without JavaScript the form still works (redirect back to the page with the message).
 *
 * The helpers are exposed as `window.eeltForm` and reused by popup.js.
 */
( function () {
	'use strict';

	var CONNECTION_ERROR = 'A connection error occurred. Please try again.';

	function setStatus( form, ok, message ) {
		var box = form.querySelector( '.eelt-status' );
		if ( ! box ) {
			return;
		}
		box.textContent = message || '';
		box.classList.toggle( 'eelt-status--ok', !! ok );
		box.classList.toggle( 'eelt-status--error', ! ok );
	}

	function clearErrors( form ) {
		form.querySelectorAll( '.eelt-error' ).forEach( function ( el ) {
			el.textContent = '';
		} );
		form.querySelectorAll( '[aria-invalid]' ).forEach( function ( el ) {
			el.removeAttribute( 'aria-invalid' );
		} );
	}

	function showErrors( form, errors ) {
		var first = null;
		Object.keys( errors || {} ).forEach( function ( name ) {
			var slot = form.querySelector( '.eelt-error[data-for="' + name + '"]' );
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
		var widget = form.querySelector( '.cf-turnstile, .eelt-turnstile' );
		if ( widget && window.turnstile ) {
			try {
				window.turnstile.reset( widget );
			} catch ( e ) {}
		}
	}

	/**
	 * Sends the form. Resolves with the server response: { ok, status, message, errors }.
	 * A network failure resolves with { ok: false, status: 'connection', message }.
	 */
	function submit( form ) {
		// getAttribute(): `form.action` would return the hidden <input name="action"> instead of the URL.
		return fetch( form.getAttribute( 'action' ), {
			method: 'POST',
			body: new FormData( form ),
			credentials: 'same-origin',
			headers: { 'X-EELT-Ajax': '1', Accept: 'application/json' },
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.catch( function () {
				return { ok: false, status: 'connection', message: CONNECTION_ERROR, errors: {} };
			} );
	}

	function onSubmit( event ) {
		var form = event.target;
		if ( ! form.classList || ! form.classList.contains( 'eelt-form' ) || ! window.fetch || ! window.FormData ) {
			return;
		}
		// Forms inside the popup are submitted by the popup's confirm button (popup.js).
		if ( form.closest( '.eelt-swal' ) ) {
			event.preventDefault();
			return;
		}
		event.preventDefault();

		var button = form.querySelector( '.eelt-submit' );
		if ( button && button.disabled ) {
			return;
		}
		if ( button ) {
			button.disabled = true;
		}
		form.setAttribute( 'aria-busy', 'true' );
		clearErrors( form );

		submit( form ).then( function ( data ) {
			setStatus( form, data.ok, data.message );
			if ( data.ok ) {
				form.reset();
			} else {
				showErrors( form, data.errors );
			}
			if ( button ) {
				button.disabled = false;
			}
			form.removeAttribute( 'aria-busy' );
			resetTurnstile( form );
		} );
	}

	window.eeltForm = {
		submit: submit,
		clearErrors: clearErrors,
		showErrors: showErrors,
		resetTurnstile: resetTurnstile,
	};

	document.addEventListener( 'submit', onSubmit );
} )();
