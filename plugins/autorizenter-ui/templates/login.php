<?php
/**
 * Login buttons template.
 *
 * @package Autorizenter\UI
 *
 * @var array  $providers  Provider_Base[] keyed by id.
 * @var string $return_to  Post-login destination.
 * @var string $error      Error code from a previous attempt, if any.
 * @var string $context_id Login context id.
 */

defined( 'ABSPATH' ) || exit;
$autorizenter_ctx = isset( $context_id ) ? $context_id : 'default';
?>
<div class="autorizenter-login">
	<?php if ( '' !== $error ) : ?>
		<p class="autorizenter-login__error" role="alert">
			<?php esc_html_e( 'Sign-in could not be completed. Please try again.', 'autorizenter' ); ?>
		</p>
	<?php endif; ?>

	<?php if ( empty( $providers ) ) : ?>
		<p class="autorizenter-login__empty">
			<?php esc_html_e( 'No sign-in methods are configured yet.', 'autorizenter' ); ?>
		</p>
	<?php else : ?>
		<ul class="autorizenter-login__list">
			<?php foreach ( $providers as $provider_id => $provider ) : ?>
				<?php
				$url = add_query_arg(
					array(
						'context'   => $autorizenter_ctx,
						'return_to' => rawurlencode( $return_to ),
					),
					rest_url( 'autorizenter/v1/authorize/' . $provider_id )
				);
				?>
				<li class="autorizenter-login__item">
					<a class="autorizenter-btn autorizenter-btn--<?php echo esc_attr( $provider_id ); ?>" href="<?php echo esc_url( $url ); ?>">
						<span class="autorizenter-btn__icon">
							<?php
							$autorizenter_logo = $provider->logo_url();
							if ( '' !== $autorizenter_logo ) {
								printf(
									'<img src="%s" alt="" width="20" height="20" loading="lazy" />',
									esc_url( $autorizenter_logo )
								);
							} else {
								// Trusted static SVG markup (no user input).
								echo \Autorizenter\UI\Logos::svg( $provider_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</span>
						<span class="autorizenter-btn__label">
							<?php
							/* translators: %s: provider label */
							printf( esc_html__( 'Continue with %s', 'autorizenter' ), esc_html( $provider->label() ) );
							?>
						</span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
