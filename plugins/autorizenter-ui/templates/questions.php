<?php
/**
 * Post-login questions form template.
 *
 * @package Autorizenter\UI
 *
 * @var array  $questions    Pending question definitions.
 * @var string $return_to    Destination after completion.
 * @var string $done_message Optional custom success message (empty = default).
 */

defined( 'ABSPATH' ) || exit;
$done_message = isset( $done_message ) ? $done_message : '';
?>
<div class="autorizenter-questions" data-return-to="<?php echo esc_attr( $return_to ); ?>" data-done-message="<?php echo esc_attr( $done_message ); ?>">
	<?php if ( empty( $questions ) ) : ?>
		<p><?php esc_html_e( 'Thanks — nothing else is needed.', 'autorizenter' ); ?></p>
		<p><a class="autorizenter-btn" href="<?php echo esc_url( $return_to ); ?>"><?php esc_html_e( 'Continue', 'autorizenter' ); ?></a></p>
	<?php else : ?>
		<form class="autorizenter-questions__form" id="autorizenter-questions-form">
			<?php foreach ( $questions as $q ) : ?>
				<div class="autorizenter-field autorizenter-field--<?php echo esc_attr( $q['type'] ); ?>">
					<?php if ( 'checkbox' === $q['type'] ) : ?>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $q['id'] ); ?>" value="1" <?php echo $q['required'] ? 'data-required="1"' : ''; ?> />
							<?php echo esc_html( $q['label'] ); ?>
							<?php echo $q['required'] ? '<span class="autorizenter-req">*</span>' : ''; ?>
						</label>
					<?php elseif ( in_array( $q['type'], array( 'radio', 'select' ), true ) ) : ?>
						<label class="autorizenter-field__label">
							<?php echo esc_html( $q['label'] ); ?>
							<?php echo $q['required'] ? '<span class="autorizenter-req">*</span>' : ''; ?>
						</label>
						<?php if ( 'select' === $q['type'] ) : ?>
							<select name="<?php echo esc_attr( $q['id'] ); ?>" <?php echo $q['required'] ? 'data-required="1"' : ''; ?>>
								<option value=""><?php esc_html_e( '— choose —', 'autorizenter' ); ?></option>
								<?php foreach ( $q['options'] as $opt ) : ?>
									<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
								<?php endforeach; ?>
							</select>
						<?php else : ?>
							<?php foreach ( $q['options'] as $opt ) : ?>
								<label class="autorizenter-radio">
									<input type="radio" name="<?php echo esc_attr( $q['id'] ); ?>" value="<?php echo esc_attr( $opt ); ?>" <?php echo $q['required'] ? 'data-required="1"' : ''; ?> />
									<?php echo esc_html( $opt ); ?>
								</label>
							<?php endforeach; ?>
						<?php endif; ?>
					<?php elseif ( 'textarea' === $q['type'] ) : ?>
						<label class="autorizenter-field__label">
							<?php echo esc_html( $q['label'] ); ?>
							<?php echo $q['required'] ? '<span class="autorizenter-req">*</span>' : ''; ?>
						</label>
						<textarea name="<?php echo esc_attr( $q['id'] ); ?>" rows="4" <?php echo $q['required'] ? 'data-required="1"' : ''; ?>></textarea>
					<?php else : ?>
						<label class="autorizenter-field__label">
							<?php echo esc_html( $q['label'] ); ?>
							<?php echo $q['required'] ? '<span class="autorizenter-req">*</span>' : ''; ?>
						</label>
						<input type="text" name="<?php echo esc_attr( $q['id'] ); ?>" <?php echo $q['required'] ? 'data-required="1"' : ''; ?> />
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<p class="autorizenter-questions__actions">
				<button type="submit" class="autorizenter-btn"><?php esc_html_e( 'Submit', 'autorizenter' ); ?></button>
			</p>
			<p class="autorizenter-questions__message" role="status" aria-live="polite"></p>
		</form>
	<?php endif; ?>
</div>
