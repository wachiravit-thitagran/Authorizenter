/**
 * Authorizenter UI — questions form submission against the Core REST API.
 */
( function () {
	'use strict';

	var form = document.getElementById( 'authorizenter-questions-form' );
	if ( ! form || typeof window.AuthorizenterUI === 'undefined' ) {
		return;
	}

	var container = form.closest( '.authorizenter-questions' );
	var message = form.querySelector( '.authorizenter-questions__message' );
	var returnTo = container ? container.getAttribute( 'data-return-to' ) : '/';
	var doneMessage = container ? container.getAttribute( 'data-done-message' ) : '';

	function setMessage( text, kind ) {
		if ( ! message ) {
			return;
		}
		message.textContent = text;
		message.className = 'authorizenter-questions__message' + ( kind ? ' is-' + kind : '' );
	}

	function collectAnswers() {
		var answers = {};
		var named = form.querySelectorAll( '[name]' );
		named.forEach( function ( el ) {
			var name = el.getAttribute( 'name' );
			if ( el.type === 'checkbox' ) {
				answers[ name ] = el.checked;
			} else if ( el.type === 'radio' ) {
				if ( el.checked ) {
					answers[ name ] = el.value;
				} else if ( ! ( name in answers ) ) {
					answers[ name ] = '';
				}
			} else {
				answers[ name ] = el.value;
			}
		} );
		return answers;
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		setMessage( '', '' );

		fetch( window.AuthorizenterUI.restUrl + '/answers', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.AuthorizenterUI.nonce
			},
			credentials: 'same-origin',
			body: JSON.stringify( { answers: collectAnswers() } )
		} )
			.then( function ( res ) {
				return res.json().then( function ( data ) {
					return { ok: res.ok, data: data };
				} );
			} )
			.then( function ( result ) {
				if ( ! result.ok ) {
					setMessage( result.data && result.data.message ? result.data.message : 'Error', 'error' );
					return;
				}
				if ( result.data.pending && result.data.pending.length > 0 ) {
					setMessage( 'Some required answers are still missing.', 'error' );
					return;
				}
				// Custom message: show it and stay on the page. Otherwise show the
				// default note and redirect to the configured destination.
				if ( doneMessage ) {
					setMessage( doneMessage, 'ok' );
					form.style.display = 'none';
					return;
				}
				setMessage( 'Saved. Redirecting…', 'ok' );
				window.location.href = returnTo || '/';
			} )
			.catch( function () {
				setMessage( 'Network error. Please try again.', 'error' );
			} );
	} );
} )();
