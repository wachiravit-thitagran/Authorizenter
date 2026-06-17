<?php
/**
 * Login buttons template.
 *
 * @package Authorizenter\UI
 *
 * @var array  $providers  Provider_Base[] keyed by id.
 * @var string $return_to  Post-login destination.
 * @var string $error      Error code from a previous attempt, if any.
 * @var string $context_id Login context id.
 */

defined( 'ABSPATH' ) || exit;
$authorizenter_ctx = isset( $context_id ) ? $context_id : 'default';
?>
<div class="authorizenter-login">
	<p class="authorizenter-login__error" role="alert" 
	<?php
	if ( '' === $error ) {
		echo 'style="display:none;"';}
	?>
	>
		<?php
		if ( '' !== $error ) {
			esc_html_e( 'Sign-in could not be completed. Please try again.', 'authorizenter' );}
		?>
	</p>

	<?php if ( empty( $providers ) ) : ?>
		<p class="authorizenter-login__empty">
			<?php esc_html_e( 'No sign-in methods are configured yet.', 'authorizenter' ); ?>
		</p>
	<?php else : ?>
		<?php
		$action_url = rest_url( 'authorizenter/v1/authorize' );
		?>
		<form method="POST" action="<?php echo esc_url( $action_url ); ?>" class="authorizenter-login__form" id="authorizenter-login-form">
			<input type="hidden" name="context" value="<?php echo esc_attr( $authorizenter_ctx ); ?>" />
			<?php if ( '' !== $return_to ) : ?>
				<input type="hidden" name="return_to" value="<?php echo esc_attr( $return_to ); ?>" />
			<?php endif; ?>

			<?php
			/**
			 * Allows other plugins to inject custom form fields (e.g. Terms of Service checkbox)
			 * into the login form.
			 */
			do_action( 'authorizenter_login_form' );
			?>

			<ul class="authorizenter-login__list">
				<?php foreach ( $providers as $provider_id => $provider ) : ?>
					<?php
					$onclick                = '';
					$authorizenter_is_login = isset( $is_login_page ) ? $is_login_page : false;
					if ( '' === $return_to && ! $authorizenter_is_login ) {
						$onclick = ' onclick="document.cookie=\'authorizenter_redirect=\' + encodeURIComponent(window.location.href) + \'; path=/\';"';
					}
					?>
					<li class="authorizenter-login__item">
						<button type="submit" name="provider" value="<?php echo esc_attr( $provider_id ); ?>" class="authorizenter-btn authorizenter-btn--<?php echo esc_attr( $provider_id ); ?>"<?php echo $onclick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
							<span class="authorizenter-btn__icon">
								<?php
								$authorizenter_logo = $provider->logo_url();
								if ( '' !== $authorizenter_logo ) {
									printf(
										'<img src="%s" alt="" width="20" height="20" loading="lazy" />',
										esc_url( $authorizenter_logo )
									);
								} else {
									// Trusted static SVG markup (no user input).
									echo \Authorizenter\UI\Logos::svg( $provider_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
								}
								?>
							</span>
							<span class="authorizenter-btn__label">
								<?php
								/* translators: %s: provider label */
								printf( esc_html__( 'Continue with %s', 'authorizenter' ), esc_html( $provider->label() ) );
								?>
							</span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>
		</form>
	<?php endif; ?>
</div>
