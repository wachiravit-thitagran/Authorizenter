<?php
/**
 * Pre-approval form template.
 *
 * Shown to users who were placed in pending state. Answers are saved via the
 * POST /pending/answers REST endpoint and displayed in the admin pending list.
 *
 * @package Authorizenter\UI
 *
 * @var array  $questions All configured question definitions.
 * @var string $token     One-time pending token from the redirect URL.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="authorizenter-pending-form" data-token="<?php echo esc_attr( $token ); ?>">
	<form class="authorizenter-questions__form" id="authorizenter-pending-form" method="get">
		<?php // Keep the token in the URL even on a native (no-JS) submit. ?>
		<input type="hidden" name="azr_pending_token" value="<?php echo esc_attr( $token ); ?>" />
		<?php foreach ( $questions as $q ) : ?>
			<div class="authorizenter-field authorizenter-field--<?php echo esc_attr( $q['type'] ); ?>">
				<?php if ( 'checkbox' === $q['type'] ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $q['id'] ); ?>" value="1" <?php echo $q['required'] ? 'data-required="1"' : ''; ?> />
						<?php echo esc_html( $q['label'] ); ?>
						<?php echo $q['required'] ? '<span class="authorizenter-req">*</span>' : ''; ?>
					</label>
				<?php elseif ( in_array( $q['type'], array( 'radio', 'select' ), true ) ) : ?>
					<label class="authorizenter-field__label">
						<?php echo esc_html( $q['label'] ); ?>
						<?php echo $q['required'] ? '<span class="authorizenter-req">*</span>' : ''; ?>
					</label>
					<?php if ( 'select' === $q['type'] ) : ?>
						<select name="<?php echo esc_attr( $q['id'] ); ?>" <?php echo $q['required'] ? 'data-required="1"' : ''; ?>>
							<option value=""><?php esc_html_e( '— choose —', 'authorizenter' ); ?></option>
							<?php foreach ( $q['options'] as $opt ) : ?>
								<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php else : ?>
						<?php foreach ( $q['options'] as $opt ) : ?>
							<label class="authorizenter-radio">
								<input type="radio" name="<?php echo esc_attr( $q['id'] ); ?>" value="<?php echo esc_attr( $opt ); ?>" <?php echo $q['required'] ? 'data-required="1"' : ''; ?> />
								<?php echo esc_html( $opt ); ?>
							</label>
						<?php endforeach; ?>
					<?php endif; ?>
				<?php elseif ( 'textarea' === $q['type'] ) : ?>
					<label class="authorizenter-field__label">
						<?php echo esc_html( $q['label'] ); ?>
						<?php echo $q['required'] ? '<span class="authorizenter-req">*</span>' : ''; ?>
					</label>
					<textarea name="<?php echo esc_attr( $q['id'] ); ?>" rows="4" <?php echo $q['required'] ? 'data-required="1"' : ''; ?>></textarea>
				<?php else : ?>
					<label class="authorizenter-field__label">
						<?php echo esc_html( $q['label'] ); ?>
						<?php echo $q['required'] ? '<span class="authorizenter-req">*</span>' : ''; ?>
					</label>
					<input type="text" name="<?php echo esc_attr( $q['id'] ); ?>" <?php echo $q['required'] ? 'data-required="1"' : ''; ?> />
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<p class="authorizenter-questions__actions">
			<button type="submit" class="authorizenter-btn"><?php esc_html_e( 'Submit', 'authorizenter' ); ?></button>
		</p>
		<p class="authorizenter-questions__message" role="status" aria-live="polite"></p>
	</form>
</div>
<script>
( function () {
	'use strict';
	// REST base injected via PHP so this does not depend on the footer-loaded
	// AuthorizenterUI object (which is printed after this inline script and would
	// otherwise be undefined here, causing a native form submit that drops the
	// token from the URL).
	var restUrl = <?php echo wp_json_encode( esc_url_raw( rest_url( 'authorizenter/v1' ) ) ); ?>;
	var doneMessage = <?php echo wp_json_encode( isset( $done_message ) ? (string) $done_message : '' ); ?>;
	var redirectTo = <?php echo wp_json_encode( isset( $redirect ) ? (string) $redirect : '' ); ?>;
	var form = document.getElementById( 'authorizenter-pending-form' );
	if ( ! form ) {
		return;
	}
	var container = form.closest( '.authorizenter-pending-form' );
	var message = form.querySelector( '.authorizenter-questions__message' );
	var token = container ? container.getAttribute( 'data-token' ) : '';

	function setMessage( text, kind ) {
		if ( ! message ) { return; }
		message.textContent = text;
		message.className = 'authorizenter-questions__message' + ( kind ? ' is-' + kind : '' );
	}

	function collectAnswers() {
		var answers = {};
		form.querySelectorAll( '[name]' ).forEach( function ( el ) {
			var name = el.getAttribute( 'name' );
			if ( name === 'azr_pending_token' ) {
				return; // not an answer.
			}
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

		fetch( restUrl + '/pending/answers', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( { token: token, answers: collectAnswers() } )
		} )
		.then( function ( res ) {
			return res.json().then( function ( data ) {
				return { ok: res.ok, data: data };
			} );
		} )
		.then( function ( result ) {
			if ( ! result.ok ) {
				setMessage( result.data && result.data.message ? result.data.message : '<?php echo esc_js( __( 'Error submitting form.', 'authorizenter' ) ); ?>', 'error' );
				return;
			}
			setMessage( doneMessage || '<?php echo esc_js( __( 'Received. An administrator will review your request.', 'authorizenter' ) ); ?>', 'ok' );
			form.style.display = 'none';
			if ( redirectTo ) {
				window.location.href = redirectTo;
			}
		} )
		.catch( function () {
			setMessage( '<?php echo esc_js( __( 'Network error. Please try again.', 'authorizenter' ) ); ?>', 'error' );
		} );
	} );
} )();
</script>
