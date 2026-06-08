<?php
/**
 * Admin settings screen.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Renders Settings → Autorizenter and persists configuration.
 *
 * Kept deliberately simple (a single self-posting form) so Core has no front-end
 * dependencies. The UI plugin may offer a richer experience on top of the same
 * Settings store.
 */
class Admin_Settings {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Providers.
	 *
	 * @var Provider_Registry
	 */
	private $providers;

	/**
	 * Questions.
	 *
	 * @var Questions
	 */
	private $questions;

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings store.
	 * @param Provider_Registry $providers Providers.
	 * @param Questions         $questions Questions.
	 */
	public function __construct( Settings $settings, Provider_Registry $providers, Questions $questions ) {
		$this->settings  = $settings;
		$this->providers = $providers;
		$this->questions = $questions;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_autorizenter_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Add the settings page.
	 *
	 * @return void
	 */
	public function menu() {
		add_options_page(
			__( 'Autorizenter', 'autorizenter' ),
			__( 'Autorizenter', 'autorizenter' ),
			'manage_options',
			'autorizenter',
			array( $this, 'render' )
		);
	}

	/**
	 * Persist the submitted settings.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'autorizenter' ) );
		}
		check_admin_referer( 'autorizenter_save' );

		$all = $this->settings->all();

		// Providers.
		$posted_providers = isset( $_POST['providers'] ) && is_array( $_POST['providers'] ) ? wp_unslash( $_POST['providers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		foreach ( array_keys( $this->providers->classes() ) as $id ) {
			$p   = isset( $posted_providers[ $id ] ) && is_array( $posted_providers[ $id ] ) ? $posted_providers[ $id ] : array();
			$cur = isset( $all['providers'][ $id ] ) && is_array( $all['providers'][ $id ] ) ? $all['providers'][ $id ] : array();

			$entry = array(
				'enabled'       => ! empty( $p['enabled'] ),
				'client_id'     => isset( $p['client_id'] ) ? sanitize_text_field( $p['client_id'] ) : '',
				'discovery_url' => isset( $p['discovery_url'] ) ? esc_url_raw( $p['discovery_url'] ) : '',
				'label'         => isset( $p['label'] ) ? sanitize_text_field( $p['label'] ) : '',
				'scopes'        => isset( $p['scopes'] ) ? sanitize_text_field( $p['scopes'] ) : '',
				'logo_url'      => isset( $p['logo_url'] ) ? esc_url_raw( $p['logo_url'] ) : '',
			);

			// Only overwrite the secret if a new value was entered.
			if ( isset( $p['client_secret'] ) && '' !== trim( $p['client_secret'] ) ) {
				$entry['client_secret'] = $this->settings->encrypt( sanitize_text_field( $p['client_secret'] ) );
			} elseif ( isset( $cur['client_secret'] ) ) {
				$entry['client_secret'] = $cur['client_secret'];
			} else {
				$entry['client_secret'] = '';
			}

			// OIDC-only extended fields.
			if ( 'oidc' === $id ) {
				$entry['issuer_url']                  = isset( $p['issuer_url'] ) ? esc_url_raw( $p['issuer_url'] ) : '';
				$entry['attr_username']               = isset( $p['attr_username'] ) ? sanitize_key( $p['attr_username'] ) : '';
				$entry['attr_email']                  = isset( $p['attr_email'] ) ? sanitize_key( $p['attr_email'] ) : '';
				$entry['attr_first_name']             = isset( $p['attr_first_name'] ) ? sanitize_key( $p['attr_first_name'] ) : '';
				$entry['attr_last_name']              = isset( $p['attr_last_name'] ) ? sanitize_key( $p['attr_last_name'] ) : '';
				$entry['name_update']                 = isset( $p['name_update'] ) && in_array( $p['name_update'], array( 'none', 'always', 'if_empty' ), true ) ? $p['name_update'] : 'none';
				$entry['auth_method']                 = isset( $p['auth_method'] ) && in_array( $p['auth_method'], array( 'auto', 'post', 'basic', 'secret_jwt', 'private_key_jwt' ), true ) ? $p['auth_method'] : 'auto';
				$entry['oidc_require_verified_email'] = ! empty( $p['oidc_require_verified_email'] );
				$entry['link_by_username']            = ! empty( $p['link_by_username'] );

				if ( isset( $p['private_key'] ) && '' !== trim( $p['private_key'] ) ) {
					$entry['private_key'] = $this->settings->encrypt( sanitize_textarea_field( $p['private_key'] ) );
				} elseif ( isset( $cur['private_key'] ) ) {
					$entry['private_key'] = $cur['private_key'];
				} else {
					$entry['private_key'] = '';
				}
			}

			$all['providers'][ $id ] = $entry;
		}

		// Policy.
		$domains_raw = isset( $_POST['allowed_domains'] ) ? sanitize_textarea_field( wp_unslash( $_POST['allowed_domains'] ) ) : '';
		$domains     = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $domains_raw ) ) );

		$trusted = isset( $_POST['trusted_providers'] ) && is_array( $_POST['trusted_providers'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['trusted_providers'] ) )
			: array();

		$all['policy']['enabled']                = ! empty( $_POST['policy_enabled'] );
		$all['policy']['allowed_domains']        = array_values( $domains );
		$all['policy']['require_google_hd']      = ! empty( $_POST['require_google_hd'] );
		$all['policy']['require_verified_email'] = ! empty( $_POST['require_verified_email'] );
		$all['policy']['trusted_providers']      = $trusted;
		$all['policy']['block_message']          = isset( $_POST['block_message'] ) ? sanitize_text_field( wp_unslash( $_POST['block_message'] ) ) : '';

		// Users.
		$all['users']['auto_provision'] = ! empty( $_POST['auto_provision'] );
		$all['users']['link_by_email']  = ! empty( $_POST['link_by_email'] );
		$all['users']['default_role']   = isset( $_POST['default_role'] ) ? sanitize_key( wp_unslash( $_POST['default_role'] ) ) : 'subscriber';
		$all['users']['role_map']       = $this->parse_role_map( isset( $_POST['role_map'] ) ? wp_unslash( $_POST['role_map'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Access lists.
		$all['access']['enabled']  = ! empty( $_POST['access_enabled'] );
		$all['access']['approved'] = $this->split_lines( isset( $_POST['access_approved'] ) ? wp_unslash( $_POST['access_approved'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$all['access']['blocked']  = $this->split_lines( isset( $_POST['access_blocked'] ) ? wp_unslash( $_POST['access_blocked'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// Approve selected pending identities.
		$approve = isset( $_POST['approve_pending'] ) && is_array( $_POST['approve_pending'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['approve_pending'] ) )
			: array();
		$pending = isset( $all['access']['pending'] ) ? (array) $all['access']['pending'] : array();
		if ( ! empty( $approve ) ) {
			$all['access']['approved'] = array_values( array_unique( array_merge( $all['access']['approved'], $approve ) ) );
			$pending                   = array_values( array_diff( $pending, $approve ) );
		}
		$all['access']['pending'] = $pending;

		// Login throttle.
		$all['throttle']['enabled']         = ! empty( $_POST['throttle_enabled'] );
		$all['throttle']['max_attempts']    = isset( $_POST['throttle_max'] ) ? max( 1, (int) $_POST['throttle_max'] ) : 5;
		$all['throttle']['lockout_seconds'] = isset( $_POST['throttle_lockout'] ) ? max( 30, (int) $_POST['throttle_lockout'] ) : 900;

		// Private site.
		$all['private_site']['enabled'] = ! empty( $_POST['private_site_enabled'] );

		// Questions (structured form).
		$all['questions'] = $this->questions_from_post();

		// Advanced: global deny redirect.
		$all['advanced']['deny_redirect'] = isset( $_POST['deny_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['deny_redirect'] ) ) : '';

		// Login security: disable username/password sign-in.
		$all['advanced']['disable_password_auth']      = ! empty( $_POST['disable_password_auth'] );
		$all['advanced']['password_auth_admin_bypass'] = ! empty( $_POST['password_auth_admin_bypass'] );

		// Contexts (structured form).
		$all['contexts'] = $this->contexts_from_post();

		$this->settings->save( $all );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'autorizenter',
					'updated' => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Split a textarea into trimmed, lowercased, de-duplicated non-empty lines.
	 *
	 * @param string $raw Raw textarea value.
	 * @return string[]
	 */
	private function split_lines( $raw ) {
		$lines = preg_split( '/[\r\n,]+/', (string) $raw );
		$out   = array();
		foreach ( (array) $lines as $line ) {
			$line = strtolower( trim( sanitize_text_field( $line ) ) );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Parse a "matcher = role" textarea into a role-map array.
	 *
	 * @param string $raw Raw textarea value.
	 * @return array[] List of array( match, role ).
	 */
	private function parse_role_map( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n]+/', (string) $raw ) as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $match, $role ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$match                = sanitize_text_field( $match );
			$role                 = sanitize_key( $role );
			if ( '' !== $match && '' !== $role ) {
				$out[] = array(
					'match' => $match,
					'role'  => $role,
				);
			}
		}
		return $out;
	}

	/**
	 * Build the contexts map from the structured POST form (name="ctx[i][...]").
	 *
	 * Rows with an empty id are skipped (these are the blank "add new" rows).
	 * Override fields (allowed_domains, trusted_providers, auto_provision) are set
	 * to null when their "inherit" control is chosen, meaning "use global policy".
	 *
	 * @return array
	 */
	private function contexts_from_post() {
		// Nonce is verified by check_admin_referer() in the calling handle_save().
		$rows = isset( $_POST['ctx'] ) && is_array( $_POST['ctx'] ) ? wp_unslash( $_POST['ctx'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
		$out  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}

			// Provider allowlist (empty = all).
			$providers = isset( $row['providers'] ) && is_array( $row['providers'] )
				? array_values( array_map( 'sanitize_key', $row['providers'] ) )
				: array();

			// allowed_domains override.
			if ( ! empty( $row['inherit_domains'] ) ) {
				$allowed_domains = null;
			} else {
				$raw             = isset( $row['allowed_domains'] ) ? sanitize_textarea_field( $row['allowed_domains'] ) : '';
				$allowed_domains = array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $raw ) ) ) );
			}

			// trusted_providers override.
			if ( ! empty( $row['inherit_trusted'] ) ) {
				$trusted = null;
			} else {
				$trusted = isset( $row['trusted_providers'] ) && is_array( $row['trusted_providers'] )
					? array_values( array_map( 'sanitize_key', $row['trusted_providers'] ) )
					: array();
			}

			// auto_provision override: inherit | yes | no.
			$ap_raw         = isset( $row['auto_provision'] ) ? $row['auto_provision'] : 'inherit';
			$auto_provision = 'inherit' === $ap_raw ? null : ( '1' === (string) $ap_raw );

			// policy_enabled override: inherit | on | off.
			$pe_raw         = isset( $row['policy_enabled'] ) ? $row['policy_enabled'] : 'inherit';
			$policy_enabled = 'inherit' === $pe_raw ? null : ( '1' === (string) $pe_raw );

			$cap = isset( $row['required_capability'] ) ? sanitize_key( $row['required_capability'] ) : 'read';

			$questions_raw = isset( $row['questions'] ) ? sanitize_text_field( $row['questions'] ) : '';
			$questions     = array_values( array_filter( array_map( 'sanitize_key', preg_split( '/[\s,]+/', $questions_raw ) ) ) );

			$out[ $id ] = array(
				'label'               => isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '',
				'providers'           => $providers,
				'required_capability' => '' !== $cap ? $cap : 'read',
				'redirect'            => isset( $row['redirect'] ) ? esc_url_raw( $row['redirect'] ) : '',
				'deny_redirect'       => isset( $row['deny_redirect'] ) ? esc_url_raw( $row['deny_redirect'] ) : '',
				'pending_redirect'    => isset( $row['pending_redirect'] ) ? esc_url_raw( $row['pending_redirect'] ) : '',
				'questions'           => $questions,
				'policy_enabled'      => $policy_enabled,
				'allowed_domains'     => $allowed_domains,
				'trusted_providers'   => $trusted,
				'auto_provision'      => $auto_provision,
			);
		}

		// Always guarantee a default context exists.
		if ( ! isset( $out['default'] ) ) {
			$out = array_merge(
				array(
					'default' => array(
						'label'               => __( 'Sign in', 'autorizenter' ),
						'required_capability' => 'read',
					),
				),
				$out
			);
		}

		return $out;
	}

	/**
	 * Build the questions list from the structured POST form (name="question[i][...]").
	 *
	 * Rows with an empty id or label are skipped. Options are entered one per line
	 * and only meaningful for radio/select. Final sanitizing is delegated to
	 * Questions::sanitize_definition().
	 *
	 * @return array
	 */
	private function questions_from_post() {
		// Nonce is verified by check_admin_referer() in the calling handle_save().
		$rows = isset( $_POST['question'] ) && is_array( $_POST['question'] ) ? wp_unslash( $_POST['question'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
		$out  = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = isset( $row['id'] ) ? sanitize_key( $row['id'] ) : '';
			if ( '' === $id ) {
				continue;
			}

			$options = array();
			if ( ! empty( $row['options'] ) ) {
				foreach ( preg_split( '/\r\n|\r|\n/', (string) $row['options'] ) as $opt ) {
					$opt = trim( $opt );
					if ( '' !== $opt ) {
						$options[] = $opt;
					}
				}
			}

			$providers = isset( $row['providers'] ) && is_array( $row['providers'] ) ? $row['providers'] : array();

			$def = $this->questions->sanitize_definition(
				array(
					'id'        => $id,
					'type'      => isset( $row['type'] ) ? $row['type'] : 'text',
					'label'     => isset( $row['label'] ) ? $row['label'] : '',
					'required'  => ! empty( $row['required'] ),
					'options'   => $options,
					'providers' => $providers,
				)
			);
			if ( $def ) {
				$out[] = $def;
			}
		}

		return $out;
	}

	/**
	 * Common capability suggestions offered in the contexts editor.
	 *
	 * @return array cap => human label.
	 */
	private function capability_suggestions() {
		return array(
			'read'           => __( 'read — any signed-in user', 'autorizenter' ),
			'edit_posts'     => __( 'edit_posts — contributors and up', 'autorizenter' ),
			'edit_pages'     => __( 'edit_pages — editors and up', 'autorizenter' ),
			'manage_options' => __( 'manage_options — administrators', 'autorizenter' ),
		);
	}

	/**
	 * Render the structured questions editor: existing questions plus blank rows.
	 *
	 * @param array $questions    Stored question definitions.
	 * @param array $provider_ids Available provider ids.
	 * @return void
	 */
	private function render_questions_editor( array $questions, array $provider_ids ) {
		$index = 0;
		foreach ( $questions as $q ) {
			$this->render_question_row( $index, (array) $q, $provider_ids );
			++$index;
		}
		for ( $i = 0; $i < 2; $i++ ) {
			$this->render_question_row( $index, array(), $provider_ids );
			++$index;
		}
	}

	/**
	 * Render a single question as a fieldset.
	 *
	 * @param int   $index        Row index (POST array key).
	 * @param array $q            Stored question values.
	 * @param array $provider_ids Available provider ids.
	 * @return void
	 */
	private function render_question_row( $index, array $q, array $provider_ids ) {
		$name      = 'question[' . (int) $index . ']';
		$id        = isset( $q['id'] ) ? $q['id'] : '';
		$type      = isset( $q['type'] ) ? $q['type'] : 'text';
		$label     = isset( $q['label'] ) ? $q['label'] : '';
		$required  = ! empty( $q['required'] );
		$options   = isset( $q['options'] ) && is_array( $q['options'] ) ? implode( "\n", $q['options'] ) : '';
		$providers = isset( $q['providers'] ) && is_array( $q['providers'] ) ? $q['providers'] : array();
		?>
		<fieldset class="autorizenter-fieldset">
			<legend>
				<?php echo $id ? esc_html( $id ) : esc_html__( 'New question', 'autorizenter' ); ?>
			</legend>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'ID (slug)', 'autorizenter' ); ?></th>
					<td><input type="text" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" placeholder="<?php esc_attr_e( 'e.g. is_bia_volunteer', 'autorizenter' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Type', 'autorizenter' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[type]">
							<?php foreach ( $this->questions->types() as $t ) : ?>
								<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $type, $t ); ?>><?php echo esc_html( $t ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Label', 'autorizenter' ); ?></th>
					<td><input type="text" class="large-text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'The question shown to users', 'autorizenter' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Required', 'autorizenter' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[required]" value="1" <?php checked( $required ); ?> /> <?php esc_html_e( 'User must answer before continuing', 'autorizenter' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Options', 'autorizenter' ); ?></th>
					<td>
						<textarea name="<?php echo esc_attr( $name ); ?>[options]" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'One option per line', 'autorizenter' ); ?>"><?php echo esc_textarea( $options ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Used for radio and select only (one option per line). Ignored for other types.', 'autorizenter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Limit to providers', 'autorizenter' ); ?></th>
					<td>
						<?php foreach ( $provider_ids as $pid ) : ?>
							<label style="margin-right:1em;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[providers][]" value="<?php echo esc_attr( $pid ); ?>" <?php checked( in_array( $pid, $providers, true ) ); ?> /> <?php echo esc_html( ucfirst( $pid ) ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Leave all unchecked to ask everyone regardless of login method.', 'autorizenter' ); ?></p>
					</td>
				</tr>
			</table>
		</fieldset>
		<?php
	}

	/**
	 * Render the structured contexts editor: existing contexts plus blank rows
	 * for adding new ones (no JavaScript required).
	 *
	 * @param array $contexts      Stored contexts map (raw, with null overrides).
	 * @param array $provider_ids  Available provider ids.
	 * @return void
	 */
	private function render_contexts( array $contexts, array $provider_ids ) {
		if ( ! isset( $contexts['default'] ) ) {
			$contexts = array_merge( array( 'default' => array( 'required_capability' => 'read' ) ), $contexts );
		}

		$index = 0;
		foreach ( $contexts as $id => $ctx ) {
			$this->render_context_row( $index, (string) $id, (array) $ctx, $provider_ids );
			++$index;
		}

		// Two blank rows for adding new contexts.
		for ( $i = 0; $i < 2; $i++ ) {
			$this->render_context_row( $index, '', array(), $provider_ids );
			++$index;
		}
	}

	/**
	 * Render a single context as a fieldset.
	 *
	 * @param int    $index        Row index (POST array key).
	 * @param string $id           Context id ('' for a blank row).
	 * @param array  $ctx          Stored context values.
	 * @param array  $provider_ids Available provider ids.
	 * @return void
	 */
	private function render_context_row( $index, $id, array $ctx, array $provider_ids ) {
		$is_default = ( 'default' === $id );
		$name       = 'ctx[' . (int) $index . ']';

		$label        = isset( $ctx['label'] ) ? $ctx['label'] : '';
		$cap          = isset( $ctx['required_capability'] ) ? $ctx['required_capability'] : 'read';
		$redirect     = isset( $ctx['redirect'] ) ? $ctx['redirect'] : '';
		$deny         = isset( $ctx['deny_redirect'] ) ? $ctx['deny_redirect'] : '';
		$pending      = isset( $ctx['pending_redirect'] ) ? $ctx['pending_redirect'] : '';
		$providers    = isset( $ctx['providers'] ) && is_array( $ctx['providers'] ) ? $ctx['providers'] : array();
		$questions    = isset( $ctx['questions'] ) && is_array( $ctx['questions'] ) ? implode( ', ', $ctx['questions'] ) : '';
		$domains_null = ! array_key_exists( 'allowed_domains', $ctx ) || null === $ctx['allowed_domains'];
		$domains_val  = $domains_null ? '' : implode( "\n", (array) $ctx['allowed_domains'] );
		$trusted_null = ! array_key_exists( 'trusted_providers', $ctx ) || null === $ctx['trusted_providers'];
		$trusted_val  = $trusted_null ? array() : (array) $ctx['trusted_providers'];
		$ap           = array_key_exists( 'auto_provision', $ctx ) ? $ctx['auto_provision'] : null;
		$ap_sel       = ( null === $ap ) ? 'inherit' : ( $ap ? '1' : '0' );
		$pe           = array_key_exists( 'policy_enabled', $ctx ) ? $ctx['policy_enabled'] : null;
		$pe_sel       = ( null === $pe ) ? 'inherit' : ( $pe ? '1' : '0' );
		$list_id      = 'azr-caps-' . (int) $index;
		?>
		<fieldset class="autorizenter-fieldset">
			<legend>
				<?php echo $id ? esc_html( $id ) : esc_html__( 'New context', 'autorizenter' ); ?>
			</legend>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'ID (slug)', 'autorizenter' ); ?></th>
					<td>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[id]" value="<?php echo esc_attr( $id ); ?>" <?php echo $is_default ? 'readonly' : ''; ?> placeholder="<?php esc_attr_e( 'e.g. admin', 'autorizenter' ); ?>" />
						<?php if ( ! $is_default ) : ?>
							<p class="description"><?php esc_html_e( 'Used in [autorizenter_login context="…"]. Leave blank to ignore this row.', 'autorizenter' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Label', 'autorizenter' ); ?></th>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Providers', 'autorizenter' ); ?></th>
					<td>
						<?php foreach ( $provider_ids as $pid ) : ?>
							<label style="margin-right:1em;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[providers][]" value="<?php echo esc_attr( $pid ); ?>" <?php checked( in_array( $pid, $providers, true ) ); ?> /> <?php echo esc_html( ucfirst( $pid ) ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Leave all unchecked to show every enabled provider.', 'autorizenter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Required capability', 'autorizenter' ); ?></th>
					<td>
						<input type="text" list="<?php echo esc_attr( $list_id ); ?>" name="<?php echo esc_attr( $name ); ?>[required_capability]" value="<?php echo esc_attr( $cap ); ?>" />
						<datalist id="<?php echo esc_attr( $list_id ); ?>">
							<?php foreach ( $this->capability_suggestions() as $cap_key => $cap_label ) : ?>
								<option value="<?php echo esc_attr( $cap_key ); ?>"><?php echo esc_html( $cap_label ); ?></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description"><?php esc_html_e( 'WordPress capability the user must have to enter this context.', 'autorizenter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Redirect after login', 'autorizenter' ); ?></th>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[redirect]" value="<?php echo esc_attr( $redirect ); ?>" placeholder="/wp-admin/" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Deny redirect', 'autorizenter' ); ?></th>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[deny_redirect]" value="<?php echo esc_attr( $deny ); ?>" placeholder="<?php esc_attr_e( '(inherit global)', 'autorizenter' ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pending redirect', 'autorizenter' ); ?></th>
					<td>
						<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[pending_redirect]" value="<?php echo esc_attr( $pending ); ?>" placeholder="<?php esc_attr_e( '/waiting-for-approval/', 'autorizenter' ); ?>" />
						<p class="description"><?php esc_html_e( 'Where to send users who are waiting for admin approval. Leave blank to fall back to the deny redirect.', 'autorizenter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Enforce policy', 'autorizenter' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[policy_enabled]">
							<option value="inherit" <?php selected( $pe_sel, 'inherit' ); ?>><?php esc_html_e( 'Inherit global', 'autorizenter' ); ?></option>
							<option value="1" <?php selected( $pe_sel, '1' ); ?>><?php esc_html_e( 'On — apply domain/verified/hd checks', 'autorizenter' ); ?></option>
							<option value="0" <?php selected( $pe_sel, '0' ); ?>><?php esc_html_e( 'Off — allow any authenticated user', 'autorizenter' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Allowed domains', 'autorizenter' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[inherit_domains]" value="1" <?php checked( $domains_null ); ?> /> <?php esc_html_e( 'Inherit global policy', 'autorizenter' ); ?></label>
						<textarea name="<?php echo esc_attr( $name ); ?>[allowed_domains]" rows="2" class="large-text" placeholder="example.com"><?php echo esc_textarea( $domains_val ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Uncheck inherit to set domains specific to this context.', 'autorizenter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Trusted providers', 'autorizenter' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[inherit_trusted]" value="1" <?php checked( $trusted_null ); ?> /> <?php esc_html_e( 'Inherit global policy', 'autorizenter' ); ?></label><br />
						<?php foreach ( $provider_ids as $pid ) : ?>
							<label style="margin-right:1em;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[trusted_providers][]" value="<?php echo esc_attr( $pid ); ?>" <?php checked( in_array( $pid, $trusted_val, true ) ); ?> /> <?php echo esc_html( ucfirst( $pid ) ); ?></label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto-provision', 'autorizenter' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[auto_provision]">
							<option value="inherit" <?php selected( $ap_sel, 'inherit' ); ?>><?php esc_html_e( 'Inherit global', 'autorizenter' ); ?></option>
							<option value="1" <?php selected( $ap_sel, '1' ); ?>><?php esc_html_e( 'Yes — create users on first login', 'autorizenter' ); ?></option>
							<option value="0" <?php selected( $ap_sel, '0' ); ?>><?php esc_html_e( 'No — existing accounts only', 'autorizenter' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Set "No" for privileged contexts so only pre-existing accounts can enter.', 'autorizenter' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Questions', 'autorizenter' ); ?></th>
					<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[questions]" value="<?php echo esc_attr( $questions ); ?>" placeholder="<?php esc_attr_e( '(all) or comma-separated question ids', 'autorizenter' ); ?>" /></td>
				</tr>
			</table>
		</fieldset>
		<?php
	}

	/**
	 * Settings tabs used to group the long admin form.
	 *
	 * @return array<string,string> Tab id => label.
	 */
	private function settings_tabs() {
		return array(
			'providers' => __( 'Providers', 'autorizenter' ),
			'policy'    => __( 'Organization policy', 'autorizenter' ),
			'users'     => __( 'User provisioning', 'autorizenter' ),
			'access'    => __( 'Access control', 'autorizenter' ),
			'security'  => __( 'Login security', 'autorizenter' ),
			'questions' => __( 'Questions', 'autorizenter' ),
			'contexts'  => __( 'Login contexts', 'autorizenter' ),
		);
	}

	/**
	 * Render small, dependency-free admin styles and tab behavior.
	 *
	 * @return void
	 */
	private function render_tab_assets() {
		?>
		<style>
			.autorizenter-admin .autorizenter-callback {
				margin: 12px 0 16px;
				max-width: 1180px;
			}
			.autorizenter-tabs {
				margin-top: 12px;
				max-width: 1180px;
			}
			.autorizenter-tabs .nav-tab {
				margin-bottom: -1px;
			}
			.autorizenter-tab-panel {
				box-sizing: border-box;
				max-width: 1180px;
				padding: 18px 22px 24px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-top: 0;
			}
			.autorizenter-tabs-ready .autorizenter-tab-panel {
				display: none;
			}
			.autorizenter-tabs-ready .autorizenter-tab-panel.is-active {
				display: block;
			}
			.autorizenter-section-title {
				margin: 0 0 12px;
			}
			.autorizenter-fieldset {
				margin: 0 0 1rem;
				padding: 0 1rem 1rem;
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 4px;
			}
			.autorizenter-fieldset legend {
				padding: 0 .5rem;
				font-weight: 600;
			}
			@media (max-width: 782px) {
				.autorizenter-tabs .nav-tab {
					float: none;
					display: block;
					margin: 0 0 -1px;
				}
				.autorizenter-tab-panel {
					border-top: 1px solid #c3c4c7;
				}
			}
		</style>
		<script>
			document.addEventListener('DOMContentLoaded', function () {
				var root = document.getElementById('autorizenter-settings');
				if (!root) {
					return;
				}
				var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-autorizenter-tab]'));
				var panels = Array.prototype.slice.call(root.querySelectorAll('[data-autorizenter-panel]'));
				if (!tabs.length || !panels.length) {
					return;
				}
				function hasTab(id) {
					return tabs.some(function (tab) {
						return tab.getAttribute('data-autorizenter-tab') === id;
					});
				}
				function activate(id, updateHash) {
					tabs.forEach(function (tab) {
						var active = tab.getAttribute('data-autorizenter-tab') === id;
						tab.classList.toggle('nav-tab-active', active);
						tab.setAttribute('aria-selected', active ? 'true' : 'false');
					});
					panels.forEach(function (panel) {
						var active = panel.getAttribute('data-autorizenter-panel') === id;
						panel.classList.toggle('is-active', active);
						panel.hidden = !active;
					});
					if (updateHash && window.history && window.history.replaceState) {
						window.history.replaceState(null, '', '#autorizenter-tab-' + id);
					}
				}
				var initial = window.location.hash.replace('#autorizenter-tab-', '');
				if (!hasTab(initial)) {
					initial = tabs[0].getAttribute('data-autorizenter-tab');
				}
				root.classList.add('autorizenter-tabs-ready');
				activate(initial, false);
				tabs.forEach(function (tab) {
					tab.addEventListener('click', function (event) {
						event.preventDefault();
						activate(tab.getAttribute('data-autorizenter-tab'), true);
					});
				});
			});
		</script>
		<?php
	}

	/**
	 * Render the tab navigation.
	 *
	 * @param array<string,string> $tabs Tab id => label.
	 * @return void
	 */
	private function render_tabs( array $tabs ) {
		$first = true;
		?>
		<nav class="nav-tab-wrapper autorizenter-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Settings sections', 'autorizenter' ); ?>">
			<?php foreach ( $tabs as $id => $label ) : ?>
				<a
					href="#autorizenter-tab-<?php echo esc_attr( $id ); ?>"
					class="nav-tab <?php echo $first ? 'nav-tab-active' : ''; ?>"
					data-autorizenter-tab="<?php echo esc_attr( $id ); ?>"
					role="tab"
					aria-controls="autorizenter-tab-<?php echo esc_attr( $id ); ?>"
					aria-selected="<?php echo $first ? 'true' : 'false'; ?>"
				><?php echo esc_html( $label ); ?></a>
				<?php $first = false; ?>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/**
	 * Open one tab panel.
	 *
	 * @param string $id     Tab id.
	 * @param string $title  Panel title.
	 * @param bool   $active Whether this panel is active by default.
	 * @return void
	 */
	private function open_tab_panel( $id, $title, $active = false ) {
		?>
		<section
			id="autorizenter-tab-<?php echo esc_attr( $id ); ?>"
			class="autorizenter-tab-panel <?php echo $active ? 'is-active' : ''; ?>"
			data-autorizenter-panel="<?php echo esc_attr( $id ); ?>"
			role="tabpanel"
		>
			<h2 class="autorizenter-section-title"><?php echo esc_html( $title ); ?></h2>
		<?php
	}

	/**
	 * Close one tab panel.
	 *
	 * @return void
	 */
	private function close_tab_panel() {
		?>
		</section>
		<?php
	}

	/**
	 * Render the settings form.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$all      = $this->settings->all();
		$policy   = $all['policy'];
		$users    = $all['users'];
		$advanced = isset( $all['advanced'] ) ? $all['advanced'] : array();
		$access   = isset( $all['access'] ) ? $all['access'] : array();
		$throttle = isset( $all['throttle'] ) ? $all['throttle'] : array();
		$private  = isset( $all['private_site'] ) ? $all['private_site'] : array();

		$role_map_text = '';
		foreach ( (array) ( isset( $users['role_map'] ) ? $users['role_map'] : array() ) as $rule ) {
			if ( is_array( $rule ) && ! empty( $rule['match'] ) && ! empty( $rule['role'] ) ) {
				$role_map_text .= $rule['match'] . ' = ' . $rule['role'] . "\n";
			}
		}
		$classes  = $this->providers->classes();
		$callback = rest_url( AUTORIZENTER_REST_NAMESPACE . '/callback' );
		$roles    = array_keys( get_editable_roles() );
		$tabs     = $this->settings_tabs();
		?>
		<div class="wrap autorizenter-admin" id="autorizenter-settings">
			<h1><?php esc_html_e( 'Autorizenter', 'autorizenter' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'autorizenter' ); ?></p></div>
			<?php endif; ?>

			<p class="autorizenter-callback">
				<?php esc_html_e( 'Register this redirect / callback URL with each provider:', 'autorizenter' ); ?>
				<code><?php echo esc_html( $callback ); ?></code>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="autorizenter_save" />
				<?php wp_nonce_field( 'autorizenter_save' ); ?>

				<?php $this->render_tab_assets(); ?>
				<?php $this->render_tabs( $tabs ); ?>

				<?php $this->open_tab_panel( 'providers', $tabs['providers'], true ); ?>
				<?php foreach ( $classes as $id => $class ) : ?>
					<?php
					$p          = isset( $all['providers'][ $id ] ) ? $all['providers'][ $id ] : array();
					$has_secret = ! empty( $p['client_secret'] );
					$is_generic = ( 'oidc' === $id );
					?>
					<h3><?php echo esc_html( ucfirst( $id ) ); ?></h3>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Enabled', 'autorizenter' ); ?></th>
							<td><label><input type="checkbox" name="providers[<?php echo esc_attr( $id ); ?>][enabled]" value="1" <?php checked( ! empty( $p['enabled'] ) ); ?> /> <?php esc_html_e( 'Allow sign-in with this provider', 'autorizenter' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Client ID', 'autorizenter' ); ?></th>
							<td><input type="text" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][client_id]" value="<?php echo esc_attr( isset( $p['client_id'] ) ? $p['client_id'] : '' ); ?>" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Client Secret', 'autorizenter' ); ?></th>
							<td>
								<input type="password" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][client_secret]" placeholder="<?php echo $has_secret ? esc_attr__( '•••••• (stored — leave blank to keep)', 'autorizenter' ) : ''; ?>" autocomplete="new-password" />
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Label', 'autorizenter' ); ?></th>
							<td><input type="text" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][label]" value="<?php echo esc_attr( isset( $p['label'] ) ? $p['label'] : '' ); ?>" placeholder="<?php echo esc_attr( $is_generic ? __( 'SSO', 'autorizenter' ) : ucfirst( $id ) ); ?>" /></td>
						</tr>
						<?php if ( $is_generic ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Discovery URL', 'autorizenter' ); ?></th>
							<td>
								<input type="url" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][discovery_url]" value="<?php echo esc_attr( isset( $p['discovery_url'] ) ? $p['discovery_url'] : '' ); ?>" placeholder="https://idp.example.org/.well-known/openid-configuration" />
								<p class="description"><?php esc_html_e( 'Your organization IdP (Azure AD, Keycloak, Okta, university SSO, ...).', 'autorizenter' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Scopes', 'autorizenter' ); ?></th>
							<td><input type="text" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][scopes]" value="<?php echo esc_attr( isset( $p['scopes'] ) ? $p['scopes'] : '' ); ?>" placeholder="openid email profile" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Logo URL', 'autorizenter' ); ?></th>
							<td>
								<input type="url" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][logo_url]" value="<?php echo esc_attr( isset( $p['logo_url'] ) ? $p['logo_url'] : '' ); ?>" placeholder="https://example.org/logo.svg" />
								<p class="description"><?php esc_html_e( 'Optional. Shown on the SSO button instead of the default lock icon (square image, e.g. 20×20 SVG or PNG).', 'autorizenter' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Issuer URL', 'autorizenter' ); ?></th>
							<td>
								<input type="url" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][issuer_url]" value="<?php echo esc_attr( isset( $p['issuer_url'] ) ? $p['issuer_url'] : '' ); ?>" placeholder="https://idp.example.org" />
								<p class="description"><?php esc_html_e( 'Override the issuer from the discovery document. Leave blank to use the discovery value.', 'autorizenter' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Username attribute', 'autorizenter' ); ?></th>
							<td><input type="text" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][attr_username]" value="<?php echo esc_attr( isset( $p['attr_username'] ) ? $p['attr_username'] : '' ); ?>" placeholder="sub" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Email attribute', 'autorizenter' ); ?></th>
							<td><input type="text" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][attr_email]" value="<?php echo esc_attr( isset( $p['attr_email'] ) ? $p['attr_email'] : '' ); ?>" placeholder="email" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'First name attribute', 'autorizenter' ); ?></th>
							<td><input type="text" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][attr_first_name]" value="<?php echo esc_attr( isset( $p['attr_first_name'] ) ? $p['attr_first_name'] : '' ); ?>" placeholder="given_name" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last name attribute', 'autorizenter' ); ?></th>
							<td><input type="text" class="regular-text" name="providers[<?php echo esc_attr( $id ); ?>][attr_last_name]" value="<?php echo esc_attr( isset( $p['attr_last_name'] ) ? $p['attr_last_name'] : '' ); ?>" placeholder="family_name" /></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Name attribute update', 'autorizenter' ); ?></th>
							<td>
								<select name="providers[<?php echo esc_attr( $id ); ?>][name_update]">
									<option value="none" <?php selected( isset( $p['name_update'] ) ? $p['name_update'] : 'none', 'none' ); ?>><?php esc_html_e( 'Do not update first and last name fields on login', 'autorizenter' ); ?></option>
									<option value="always" <?php selected( isset( $p['name_update'] ) ? $p['name_update'] : '', 'always' ); ?>><?php esc_html_e( 'Update first and last name fields on login', 'autorizenter' ); ?></option>
									<option value="if_empty" <?php selected( isset( $p['name_update'] ) ? $p['name_update'] : '', 'if_empty' ); ?>><?php esc_html_e( 'Update first and last name fields on login only if they are empty', 'autorizenter' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Force auth method', 'autorizenter' ); ?></th>
							<td>
								<select name="providers[<?php echo esc_attr( $id ); ?>][auth_method]">
									<option value="auto" <?php selected( isset( $p['auth_method'] ) ? $p['auth_method'] : 'auto', 'auto' ); ?>><?php esc_html_e( 'Autodetect (default)', 'autorizenter' ); ?></option>
									<option value="post" <?php selected( isset( $p['auth_method'] ) ? $p['auth_method'] : '', 'post' ); ?>><?php esc_html_e( 'Post body (client_secret_post)', 'autorizenter' ); ?></option>
									<option value="basic" <?php selected( isset( $p['auth_method'] ) ? $p['auth_method'] : '', 'basic' ); ?>><?php esc_html_e( 'Authorization header (client_secret_basic)', 'autorizenter' ); ?></option>
									<option value="secret_jwt" <?php selected( isset( $p['auth_method'] ) ? $p['auth_method'] : '', 'secret_jwt' ); ?>><?php esc_html_e( 'JWT assertion (client_secret_jwt)', 'autorizenter' ); ?></option>
									<option value="private_key_jwt" <?php selected( isset( $p['auth_method'] ) ? $p['auth_method'] : '', 'private_key_jwt' ); ?>><?php esc_html_e( 'JWT assertion with private key (private_key_jwt)', 'autorizenter' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Private key (PEM)', 'autorizenter' ); ?></th>
							<td>
								<textarea name="providers[<?php echo esc_attr( $id ); ?>][private_key]" rows="6" class="large-text code" placeholder="<?php esc_attr_e( '-----BEGIN RSA PRIVATE KEY----- (used only for private_key_jwt)', 'autorizenter' ); ?>"></textarea>
								<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the existing key.', 'autorizenter' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Require verified email', 'autorizenter' ); ?></th>
							<td><label><input type="checkbox" name="providers[<?php echo esc_attr( $id ); ?>][oidc_require_verified_email]" value="1" <?php checked( ! empty( $p['oidc_require_verified_email'] ) ); ?> /> <?php esc_html_e( 'User must have a verified email address (email_verified claim) to sign in.', 'autorizenter' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Link users by username', 'autorizenter' ); ?></th>
							<td><label><input type="checkbox" name="providers[<?php echo esc_attr( $id ); ?>][link_by_username]" value="1" <?php checked( ! empty( $p['link_by_username'] ) ); ?> /> <?php esc_html_e( 'Match OIDC users to existing WordPress accounts by username before trying email.', 'autorizenter' ); ?></label></td>
						</tr>
						<?php endif; ?>
					</table>
				<?php endforeach; ?>

				<?php $this->close_tab_panel(); ?>
				<?php $this->open_tab_panel( 'policy', $tabs['policy'] ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enforce organization policy', 'autorizenter' ); ?></th>
						<td>
							<label><input type="checkbox" name="policy_enabled" value="1" <?php checked( ! empty( $policy['enabled'] ) ); ?> /> <?php esc_html_e( 'Restrict sign-in using the rules below. When off, any authenticated user is allowed (capability checks per context still apply).', 'autorizenter' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allowed email domains', 'autorizenter' ); ?></th>
						<td>
							<textarea name="allowed_domains" rows="3" class="large-text" placeholder="example.com"><?php echo esc_textarea( implode( "\n", (array) $policy['allowed_domains'] ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One domain per line (or comma-separated). Subdomains are matched. Leave empty to allow any domain.', 'autorizenter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require verified email', 'autorizenter' ); ?></th>
						<td><label><input type="checkbox" name="require_verified_email" value="1" <?php checked( ! empty( $policy['require_verified_email'] ) ); ?> /> <?php esc_html_e( 'Reject providers that do not assert a verified email (when domains are set).', 'autorizenter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require Google hd claim', 'autorizenter' ); ?></th>
						<td><label><input type="checkbox" name="require_google_hd" value="1" <?php checked( ! empty( $policy['require_google_hd'] ) ); ?> /> <?php esc_html_e( 'For Google: require the Workspace hosted-domain claim to match.', 'autorizenter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Trusted providers', 'autorizenter' ); ?></th>
						<td>
							<?php foreach ( array_keys( $classes ) as $id ) : ?>
								<label style="margin-right:1em;"><input type="checkbox" name="trusted_providers[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, (array) $policy['trusted_providers'], true ) ); ?> /> <?php echo esc_html( ucfirst( $id ) ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Identities from these providers bypass domain checks (e.g. your own org IdP).', 'autorizenter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Block message', 'autorizenter' ); ?></th>
						<td><input type="text" class="large-text" name="block_message" value="<?php echo esc_attr( $policy['block_message'] ); ?>" /></td>
					</tr>
				</table>

				<?php $this->close_tab_panel(); ?>
				<?php $this->open_tab_panel( 'users', $tabs['users'] ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-provision', 'autorizenter' ); ?></th>
						<td><label><input type="checkbox" name="auto_provision" value="1" <?php checked( ! empty( $users['auto_provision'] ) ); ?> /> <?php esc_html_e( 'Create a WordPress user automatically on first login.', 'autorizenter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Link by verified email', 'autorizenter' ); ?></th>
						<td><label><input type="checkbox" name="link_by_email" value="1" <?php checked( ! empty( $users['link_by_email'] ) ); ?> /> <?php esc_html_e( 'Attach logins to an existing user with the same verified email.', 'autorizenter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default role', 'autorizenter' ); ?></th>
						<td>
							<select name="default_role">
								<?php foreach ( $roles as $role ) : ?>
									<option value="<?php echo esc_attr( $role ); ?>" <?php selected( $users['default_role'], $role ); ?>><?php echo esc_html( $role ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Role mapping', 'autorizenter' ); ?></th>
						<td>
							<textarea name="role_map" rows="3" class="large-text code" placeholder="domain:example.com = editor&#10;provider:oidc = author"><?php echo esc_textarea( $role_map_text ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One rule per line: "matcher = role". Matchers: domain:, provider:, email:, email_regex: (full-email regex, e.g. email_regex:^\d+@abc\.co\.th$), or *. First match wins; otherwise the default role applies (new users only).', 'autorizenter' ); ?></p>
						</td>
					</tr>
				</table>

				<?php $this->close_tab_panel(); ?>
				<?php $this->open_tab_panel( 'access', __( 'Access control (per user / domain)', 'autorizenter' ) ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Restrict to approved list', 'autorizenter' ); ?></th>
						<td><label><input type="checkbox" name="access_enabled" value="1" <?php checked( ! empty( $access['enabled'] ) ); ?> /> <?php esc_html_e( 'Only identities on the approved list may sign in. Others are recorded as pending.', 'autorizenter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Approved', 'autorizenter' ); ?></th>
						<td>
							<textarea name="access_approved" rows="4" class="large-text" placeholder="alice@example.com&#10;team.example.com"><?php echo esc_textarea( implode( "\n", (array) ( isset( $access['approved'] ) ? $access['approved'] : array() ) ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One email or domain per line. Domains match subdomains too.', 'autorizenter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Blocked', 'autorizenter' ); ?></th>
						<td>
							<textarea name="access_blocked" rows="3" class="large-text" placeholder="spammer@example.com"><?php echo esc_textarea( implode( "\n", (array) ( isset( $access['blocked'] ) ? $access['blocked'] : array() ) ) ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Always denied, regardless of any other setting.', 'autorizenter' ); ?></p>
						</td>
					</tr>
					<?php $pending = isset( $access['pending'] ) ? (array) $access['pending'] : array(); ?>
					<?php if ( ! empty( $pending ) ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Pending approval', 'autorizenter' ); ?></th>
						<td>
							<?php foreach ( $pending as $p_email ) : ?>
								<label style="display:block;"><input type="checkbox" name="approve_pending[]" value="<?php echo esc_attr( $p_email ); ?>" /> <?php echo esc_html( $p_email ); ?></label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Tick to approve and save. Unticked entries remain pending.', 'autorizenter' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>

				<?php $this->close_tab_panel(); ?>
				<?php $this->open_tab_panel( 'security', $tabs['security'] ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Disable password sign-in', 'autorizenter' ); ?></th>
						<td>
							<label><input type="checkbox" name="disable_password_auth" value="1" <?php checked( ! empty( $advanced['disable_password_auth'] ) ); ?> /> <?php esc_html_e( 'Block WordPress username/password login and force single sign-on.', 'autorizenter' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Administrator bypass', 'autorizenter' ); ?></th>
						<td>
							<label><input type="checkbox" name="password_auth_admin_bypass" value="1" <?php checked( ! empty( $advanced['password_auth_admin_bypass'] ) ); ?> /> <?php esc_html_e( 'Allow administrators to still use a password (recommended — prevents lockout if SSO breaks).', 'autorizenter' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Private site', 'autorizenter' ); ?></th>
						<td><label><input type="checkbox" name="private_site_enabled" value="1" <?php checked( ! empty( $private['enabled'] ) ); ?> /> <?php esc_html_e( 'Require login to view any front-end content (anonymous visitors are redirected to sign in).', 'autorizenter' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Throttle failed logins', 'autorizenter' ); ?></th>
						<td>
							<label><input type="checkbox" name="throttle_enabled" value="1" <?php checked( ! empty( $throttle['enabled'] ) ); ?> /> <?php esc_html_e( 'Lock out an IP after repeated failed password attempts (brute-force protection).', 'autorizenter' ); ?></label>
							<p style="margin-top:8px;">
								<label><?php esc_html_e( 'Max attempts', 'autorizenter' ); ?> <input type="number" min="1" name="throttle_max" value="<?php echo esc_attr( isset( $throttle['max_attempts'] ) ? $throttle['max_attempts'] : 5 ); ?>" style="width:5em;" /></label>
								&nbsp;
								<label><?php esc_html_e( 'Lockout (seconds)', 'autorizenter' ); ?> <input type="number" min="30" name="throttle_lockout" value="<?php echo esc_attr( isset( $throttle['lockout_seconds'] ) ? $throttle['lockout_seconds'] : 900 ); ?>" style="width:7em;" /></label>
							</p>
						</td>
					</tr>
				</table>

				<?php $this->close_tab_panel(); ?>
				<?php $this->open_tab_panel( 'questions', $tabs['questions'] ); ?>
				<p class="description"><?php esc_html_e( 'Post-login questions. Each question is a fieldset; leave a blank row\'s ID empty to ignore it. Options apply to radio/select only (one per line).', 'autorizenter' ); ?></p>
				<?php
				$this->render_questions_editor( isset( $all['questions'] ) ? (array) $all['questions'] : array(), array_keys( $classes ) );
				?>

				<?php $this->close_tab_panel(); ?>
				<?php $this->open_tab_panel( 'contexts', $tabs['contexts'] ); ?>
				<p class="description">
					<?php esc_html_e( 'Named login profiles. Place a context on any page with [autorizenter_login context="id"]. Each context can show a subset of providers, apply its own policy, require a capability, and redirect differently.', 'autorizenter' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Global deny redirect', 'autorizenter' ); ?></th>
						<td>
							<input type="url" class="large-text" name="deny_redirect" value="<?php echo esc_attr( isset( $all['advanced']['deny_redirect'] ) ? $all['advanced']['deny_redirect'] : '' ); ?>" placeholder="https://example.org/no-access" />
							<p class="description"><?php esc_html_e( 'Fallback when a context denies access and has no deny_redirect of its own. If empty, users return to the context login page with an error.', 'autorizenter' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				$this->render_contexts( isset( $all['contexts'] ) ? (array) $all['contexts'] : array(), array_keys( $classes ) );
				?>

				<?php $this->close_tab_panel(); ?>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
