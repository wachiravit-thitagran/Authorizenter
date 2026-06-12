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
	<?php if ( '' !== $error ) : ?>
		<p class="authorizenter-login__error" role="alert">
			<?php esc_html_e( 'Sign-in could not be completed. Please try again.', 'authorizenter' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( empty( $providers ) ) : ?>
		<p class="authorizenter-login__empty">
			<?php esc_html_e( 'No sign-in methods are configured yet.', 'authorizenter' ); ?>
		</p>
	<?php else : ?>
		<ul class="authorizenter-login__list">
			<?php foreach ( $providers as $provider_id => $provider ) : ?>
				<?php
				$query_args = array( 'context' => $authorizenter_ctx );
				if ( '' !== $return_to ) {
					$query_args['return_to'] = rawurlencode( $return_to );
				}
				$url = add_query_arg(
					$query_args,
					rest_url( 'authorizenter/v1/authorize/' . $provider_id )
				);

				$onclick = '';
				$authorizenter_is_login = isset( $is_login_page ) ? $is_login_page : false;
				if ( '' === $return_to && ! $authorizenter_is_login ) {
					$onclick = ' onclick="document.cookie=\'authorizenter_redirect=\' + encodeURIComponent(window.location.href) + \'; path=/\';"';
				}
				?>
				<li class="authorizenter-login__item">
					<a class="authorizenter-btn authorizenter-btn--<?php echo esc_attr( $provider_id ); ?>" href="<?php echo esc_url( $url ); ?>"<?php echo $onclick; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
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
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
