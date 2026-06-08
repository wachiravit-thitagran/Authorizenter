<?php
/**
 * Settings storage with light encryption for secrets.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes the single options blob used by Autorizenter.
 *
 * Provider client secrets are stored encrypted-at-rest using AUTH_KEY/AUTH_SALT
 * derived keys. This is obfuscation against casual DB dumps, not a substitute for
 * securing the database — but it keeps plaintext secrets out of the options table.
 */
class Settings {

	const OPTION = 'autorizenter_settings';

	/**
	 * Cached settings array.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Default settings structure.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'providers'    => array(
				// Keyed by provider id; each entry holds the enabled flag, client id,
				// encrypted client secret, discovery URL and any extra fields.
			),
			'policy'       => array(
				// Organization policy is OPT-IN. When disabled, any authenticated
				// user is allowed (subject to per-context capability checks).
				'enabled'                => false,
				'allowed_domains'        => array(),
				'require_google_hd'      => false,
				'require_verified_email' => true,
				// Providers listed here are trusted as in-org regardless of domain.
				'trusted_providers'      => array(),
				'block_message'          => __( 'Your account is not permitted to sign in to this site.', 'autorizenter' ),
			),
			'users'        => array(
				'auto_provision' => true,
				'default_role'   => 'subscriber',
				'link_by_email'  => true,
				// Map matchers (domain:/provider:/email:/*) to roles; first match wins.
				'role_map'       => array(),
			),
			'access'       => array(
				// Per-user/-domain access lists (Authorizer-style).
				'enabled'  => false, // when true, only approved identities may sign in.
				'approved' => array(),
				'blocked'  => array(), // always denied, regardless of other settings.
				'pending'  => array(), // unapproved identities collected for review.
			),
			'throttle'     => array(
				'enabled'         => true,
				'max_attempts'    => 5,
				'lockout_seconds' => 900,
			),
			'private_site' => array(
				'enabled' => false,
			),
			'questions'    => array(
				// list of question definitions, see Questions::schema().
			),
			'contexts'     => array(
				// Named login profiles. The "default" context always exists.
				'default' => array(
					'label'               => __( 'Sign in', 'autorizenter' ),
					'providers'           => array(), // empty = all enabled providers.
					'required_capability' => 'read',  // every logged-in user has this.
					'redirect'            => '',       // empty = use return_to / home.
					'deny_redirect'       => '',
					'pending_redirect'    => '',
					'questions'           => array(),  // empty = all questions; or list of ids.
					// These keys override the global policy/users values when set (null = inherit global).
					'policy_enabled'      => null,
					'allowed_domains'     => null,
					'trusted_providers'   => null,
					'auto_provision'      => null,
				),
			),
			'advanced'     => array(
				'redirect_after_login'       => '',
				'deny_redirect'              => '', // global fallback when a context denies access.
				// Disable WordPress username/password sign-in (force SSO).
				'disable_password_auth'      => false,
				// Keep a way in for admins so a broken IdP can't lock everyone out.
				'password_auth_admin_bypass' => true,
			),
		);
	}

	/**
	 * Get the full settings array (with defaults merged).
	 *
	 * @return array
	 */
	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = $this->merge_defaults( is_array( $stored ) ? $stored : array() );
		}
		return $this->cache;
	}

	/**
	 * Recursively merge stored values over defaults.
	 *
	 * @param array $stored Stored values.
	 * @return array
	 */
	private function merge_defaults( $stored ) {
		$defaults = $this->defaults();
		foreach ( $defaults as $key => $value ) {
			if ( is_array( $value ) && isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ) {
				$stored[ $key ] = array_merge( $value, $stored[ $key ] );
			} elseif ( ! isset( $stored[ $key ] ) ) {
				$stored[ $key ] = $value;
			}
		}
		return $stored;
	}

	/**
	 * Get a top-level section.
	 *
	 * @param string $section Section key.
	 * @return array
	 */
	public function get( $section ) {
		$all = $this->all();
		return isset( $all[ $section ] ) && is_array( $all[ $section ] ) ? $all[ $section ] : array();
	}

	/**
	 * Resolve a login context by id, with global fallbacks applied.
	 *
	 * Returns a fully-populated context array so callers never need to know which
	 * values came from the context vs. the global policy/users sections.
	 *
	 * @param string $id Context id (defaults to "default").
	 * @return array
	 */
	public function get_context( $id = 'default' ) {
		$id       = '' !== (string) $id ? sanitize_key( $id ) : 'default';
		$all      = $this->all();
		$contexts = isset( $all['contexts'] ) && is_array( $all['contexts'] ) ? $all['contexts'] : array();
		$ctx      = isset( $contexts[ $id ] ) && is_array( $contexts[ $id ] ) ? $contexts[ $id ] : array();

		// Unknown context falls back to "default" if that exists, else empty.
		if ( empty( $ctx ) && 'default' !== $id && isset( $contexts['default'] ) ) {
			$ctx = $contexts['default'];
		}

		$policy   = isset( $all['policy'] ) ? $all['policy'] : array();
		$users    = isset( $all['users'] ) ? $all['users'] : array();
		$advanced = isset( $all['advanced'] ) ? $all['advanced'] : array();

		$resolved = array(
			'id'                     => $id,
			'label'                  => isset( $ctx['label'] ) && '' !== $ctx['label'] ? $ctx['label'] : __( 'Sign in', 'autorizenter' ),
			'providers'              => isset( $ctx['providers'] ) && is_array( $ctx['providers'] ) ? array_map( 'sanitize_key', $ctx['providers'] ) : array(),
			'required_capability'    => isset( $ctx['required_capability'] ) && '' !== $ctx['required_capability'] ? sanitize_key( $ctx['required_capability'] ) : 'read',
			'redirect'               => isset( $ctx['redirect'] ) ? $ctx['redirect'] : '',
			'deny_redirect'          => isset( $ctx['deny_redirect'] ) && '' !== $ctx['deny_redirect']
				? $ctx['deny_redirect']
				: ( isset( $advanced['deny_redirect'] ) ? $advanced['deny_redirect'] : '' ),
			'pending_redirect'       => isset( $ctx['pending_redirect'] ) ? $ctx['pending_redirect'] : '',
			'questions'              => isset( $ctx['questions'] ) && is_array( $ctx['questions'] ) ? array_map( 'sanitize_key', $ctx['questions'] ) : array(),
			// Policy values: context override (when not null) else global.
			'policy_enabled'         => (bool) $this->ctx_override( $ctx, 'policy_enabled', ! empty( $policy['enabled'] ) ),
			'allowed_domains'        => $this->ctx_override( $ctx, 'allowed_domains', isset( $policy['allowed_domains'] ) ? $policy['allowed_domains'] : array() ),
			'trusted_providers'      => $this->ctx_override( $ctx, 'trusted_providers', isset( $policy['trusted_providers'] ) ? $policy['trusted_providers'] : array() ),
			'require_verified_email' => $this->ctx_override( $ctx, 'require_verified_email', ! empty( $policy['require_verified_email'] ) ),
			'require_google_hd'      => $this->ctx_override( $ctx, 'require_google_hd', ! empty( $policy['require_google_hd'] ) ),
			'block_message'          => isset( $policy['block_message'] ) ? $policy['block_message'] : '',
			// User provisioning: context override else global.
			'auto_provision'         => $this->ctx_override( $ctx, 'auto_provision', ! empty( $users['auto_provision'] ) ),
		);

		/**
		 * Filter a resolved login context.
		 *
		 * @param array  $resolved Fully resolved context.
		 * @param string $id       Context id.
		 */
		return apply_filters( 'autorizenter_context', $resolved, $id );
	}

	/**
	 * List configured context ids.
	 *
	 * @return string[]
	 */
	public function context_ids() {
		$all      = $this->all();
		$contexts = isset( $all['contexts'] ) && is_array( $all['contexts'] ) ? $all['contexts'] : array();
		$ids      = array_keys( $contexts );
		if ( ! in_array( 'default', $ids, true ) ) {
			array_unshift( $ids, 'default' );
		}
		return $ids;
	}

	/**
	 * Read a context override: returns $default when the key is null/unset.
	 *
	 * @param array  $ctx     Context array.
	 * @param string $key     Key.
	 * @param mixed  $default Global fallback.
	 * @return mixed
	 */
	private function ctx_override( $ctx, $key, $default ) {
		if ( ! array_key_exists( $key, $ctx ) || null === $ctx[ $key ] ) {
			return $default;
		}
		return $ctx[ $key ];
	}

	/**
	 * Persist the full settings array.
	 *
	 * @param array $settings Settings to save.
	 * @return void
	 */
	public function save( array $settings ) {
		$this->cache = $this->merge_defaults( $settings );
		update_option( self::OPTION, $this->cache, false );
	}

	/**
	 * Set defaults on first activation if nothing stored.
	 *
	 * @return void
	 */
	public function maybe_set_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			update_option( self::OPTION, $this->defaults(), false );
		}
	}

	/**
	 * Encrypt a secret for storage.
	 *
	 * @param string $plaintext Secret value.
	 * @return string Base64 ciphertext, or empty string.
	 */
	public function encrypt( $plaintext ) {
		if ( '' === (string) $plaintext ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			// Fallback: store as-is (still in private DB). Strongly recommend openssl.
			return 'plain:' . base64_encode( $plaintext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$key = $this->derive_key();
		$iv  = random_bytes( 16 );
		$ct  = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return 'enc:' . base64_encode( $iv . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string Plaintext.
	 */
	public function decrypt( $stored ) {
		if ( '' === (string) $stored ) {
			return '';
		}
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return (string) base64_decode( substr( $stored, 6 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		}
		if ( 0 === strpos( $stored, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 4 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( strlen( $raw ) <= 16 ) {
				return '';
			}
			$iv = substr( $raw, 0, 16 );
			$ct = substr( $raw, 16 );
			$pt = openssl_decrypt( $ct, 'aes-256-cbc', $this->derive_key(), OPENSSL_RAW_DATA, $iv );
			return false === $pt ? '' : $pt;
		}
		return '';
	}

	/**
	 * Derive a 32-byte key from WordPress salts.
	 *
	 * @return string
	 */
	private function derive_key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : 'autorizenter' ) . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'salt' );
		return hash( 'sha256', $material, true );
	}
}
