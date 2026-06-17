/**
 * Authorizenter UI — questions form submission against the Core REST API.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
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
	} );
} )();

/**
 * Authorizenter UI — login form submission against the Core REST API.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var form = document.getElementById( 'authorizenter-login-form' );
		if ( ! form ) {
			return;
		}

		var errorElement = form.parentElement.querySelector( '.authorizenter-login__error' );

		function setError( text ) {
			if ( ! errorElement ) {
				return;
			}
			if ( text ) {
				errorElement.textContent = text;
				errorElement.style.display = 'block';
			} else {
				errorElement.style.display = 'none';
			}
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			setError( '' );

			var formData = new FormData( form );

			// Polyfill or use e.submitter to get the clicked provider button value
			var submitter = e.submitter;
			if ( submitter && submitter.name === 'provider' ) {
				// formData.append does not overwrite if we have multiple buttons, but HTML forms
				// normally only submit the clicked button. Since we use FormData(form), it doesn't
				// include the button value by default. We must append it manually.
				formData.append( 'provider', submitter.value );
			} else {
				// Fallback: If for some reason we can't get the submitter, try finding the first active button
				var activeBtn = document.activeElement;
				if ( activeBtn && activeBtn.name === 'provider' ) {
					formData.append( 'provider', activeBtn.value );
				}
			}

			// Ensure provider is set
			if ( ! formData.get( 'provider' ) ) {
				setError( 'Sign-in could not be completed. Please try again.' );
				return;
			}

			fetch( form.action, {
				method: 'POST',
				body: formData,
				headers: {
					'Accept': 'application/json'
				}
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { ok: res.ok, data: data };
					} );
				} )
				.then( function ( result ) {
					if ( ! result.ok ) {
						setError( result.data && result.data.message ? result.data.message : 'Sign-in could not be completed. Please try again.' );
						return;
					}
					if ( result.data && result.data.url ) {
						// Redirect browser to the OAuth provider
						window.location.href = result.data.url;
					} else {
						setError( 'Received invalid response from server.' );
					}
				} )
				.catch( function () {
					setError( 'Network error. Please try again.' );
				} );
		} );
	} );
} )();
