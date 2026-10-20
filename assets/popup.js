/**
 * Enspire Email Leads Tracker — [enspire_contact_popup]: a button that opens the form in SweetAlert2.
 *
 * - Field errors and retryable errors (anti-spam check, too fast) stay in the open popup, next to the fields.
 * - Success, and errors that a retry would not fix (expired form, too many messages, unavailable),
 *   replace the form with a SweetAlert2 message.
 * - Without SweetAlert2 (blocked or not loaded), the form is shown inline under the button.
 */
( function () {
	'use strict';

	/** Statuses after which the visitor can fix something and send again from the same popup. */
	var RETRYABLE = [ 'validation', 'captcha', 'too_fast', 'connection' ];

	function swal() {
		return window.Swal && typeof window.Swal.fire === 'function' ? window.Swal : null;
	}

	/** Copy of the form from the <template>, with unique IDs for each opening. */
	var opened = 0;
	function cloneForm( template ) {
		var wrapper = document.createElement( 'div' );
		wrapper.appendChild( template.content.cloneNode( true ) );
		opened++;
		wrapper.querySelectorAll( '[id]' ).forEach( function ( el ) {
			el.id = el.id + '-p' + opened;
		} );
		wrapper.querySelectorAll( 'label[for]' ).forEach( function ( el ) {
			el.setAttribute( 'for', el.getAttribute( 'for' ) + '-p' + opened );
		} );
		return wrapper;
	}

	function renderTurnstile( container ) {
		var widget = container.querySelector( '.eelt-turnstile' );
		if ( ! widget || ! window.turnstile || widget.childElementCount ) {
			return;
		}
		try {
			window.turnstile.render( widget, { sitekey: widget.getAttribute( 'data-sitekey' ) } );
		} catch ( e ) {}
	}

	/** Fallback without SweetAlert2: show the form under the button. */
	function showInline( button, template ) {
		if ( button.nextElementSibling && button.nextElementSibling.classList.contains( 'eelt-popup-inline' ) ) {
			return;
		}
		var wrapper = cloneForm( template );
		wrapper.className = 'eelt-popup-inline';
		var form = wrapper.querySelector( 'form' );
		if ( form ) {
			var actions = document.createElement( 'p' );
			actions.className = 'eelt-actions';
			actions.innerHTML = '<button type="submit" class="eelt-submit"></button>';
			actions.firstChild.textContent = button.getAttribute( 'data-eelt-submit' ) || 'Send';
			form.appendChild( actions );
			var status = document.createElement( 'div' );
			status.className = 'eelt-status';
			status.setAttribute( 'role', 'status' );
			status.setAttribute( 'aria-live', 'polite' );
			form.insertBefore( status, form.firstChild );
			// Inline mode: form.js handles the submit; a Turnstile widget needs the inline class.
			var widget = form.querySelector( '.eelt-turnstile' );
			if ( widget ) {
				widget.className = 'cf-turnstile';
			}
		}
		button.insertAdjacentElement( 'afterend', wrapper );
		renderTurnstile( wrapper );
		var first = wrapper.querySelector( '.eelt-field input, .eelt-field textarea' );
		if ( first ) {
			first.focus();
		}
	}

	function open( button ) {
		var template = document.getElementById( button.getAttribute( 'data-eelt-template' ) );
		if ( ! template || ! window.eeltForm ) {
			return;
		}
		var Swal = swal();
		if ( ! Swal ) {
			showInline( button, template );
			return;
		}

		var content = cloneForm( template );
		var form = content.querySelector( 'form' );
		if ( ! form ) {
			return;
		}
		// Enter in a single-line field submits through the popup's confirm button
		// (the popup form has no submit button of its own, so the browser would not submit it).
		form.addEventListener( 'keydown', function ( event ) {
			var target = event.target;
			if ( event.key !== 'Enter' || event.isComposing || ! target.matches( 'input:not([type="checkbox"]):not([type="radio"])' ) ) {
				return;
			}
			event.preventDefault();
			if ( ! Swal.isLoading() ) {
				Swal.clickConfirm();
			}
		} );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
		} );
		var errorTitle = button.getAttribute( 'data-eelt-error-title' ) || 'Message not sent';
		var popupClass = ( 'eelt-swal ' + ( button.getAttribute( 'data-eelt-popup-class' ) || '' ) ).trim();

		Swal.fire( {
			title: button.getAttribute( 'data-eelt-title' ) || '',
			html: content,
			showCloseButton: true,
			showConfirmButton: true,
			confirmButtonText: button.getAttribute( 'data-eelt-submit' ) || 'Send',
			showLoaderOnConfirm: true,
			focusConfirm: false,
			returnFocus: true,
			customClass: { popup: popupClass },
			allowOutsideClick: function () {
				return ! Swal.isLoading();
			},
			didOpen: function () {
				renderTurnstile( content );
				// SweetAlert2 sets its own initial focus after didOpen; move it to the first field afterwards.
				var first = content.querySelector( '.eelt-field input, .eelt-field textarea' );
				if ( first ) {
					window.setTimeout( function () {
						first.focus();
					}, 0 );
				}
			},
			preConfirm: function () {
				window.eeltForm.clearErrors( form );
				Swal.resetValidationMessage();
				return window.eeltForm.submit( form ).then( function ( data ) {
					if ( data.ok ) {
						return data;
					}
					if ( RETRYABLE.indexOf( data.status ) !== -1 ) {
						window.eeltForm.showErrors( form, data.errors );
						window.eeltForm.resetTurnstile( form );
						Swal.showValidationMessage( data.message );
						return false;
					}
					return data;
				} );
			},
		} ).then( function ( result ) {
			if ( ! result.isConfirmed || ! result.value ) {
				return;
			}
			var data = result.value;
			Swal.fire( {
				icon: data.ok ? 'success' : 'error',
				title: data.ok ? data.message : errorTitle,
				text: data.ok ? '' : data.message,
				customClass: { popup: popupClass },
				returnFocus: true,
			} );
		} );
	}

	function init() {
		document.querySelectorAll( '.eelt-popup-button[hidden]' ).forEach( function ( button ) {
			button.hidden = false;
		} );
	}

	document.addEventListener( 'click', function ( event ) {
		var button = event.target.closest && event.target.closest( '.eelt-popup-button' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		open( button );
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
