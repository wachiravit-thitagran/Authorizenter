<?php
/**
 * Maps identities to WordPress users.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves an Identity to a WP_User: links by provider+sub, then by verified
 * email, then auto-provisions a new user when allowed.
 */
class User_Mapper {

	const META_LINK_PREFIX = 'autorizenter_link_'; // + provider => sub.

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Org policy.
	 *
	 * @var Org_Policy
	 */
	private $policy;

	/**
	 * Constructor.
	 *
	 * @param Settings   $settings Settings store.
	 * @param Org_Policy $policy   Org policy.
	 */
	public function __construct( Settings $settings, Org_Policy $policy ) {
		$this->settings = $settings;
		$this->policy   = $policy;
	}

	/**
	 * Resolve (or create) the WP_User for an identity.
	 *
	 * @param Identity   $identity Identity.
	 * @param array|null $context  Optional resolved context; its auto_provision
	 *                             value overrides the global setting when present.
	 * @return \WP_User|\WP_Error
	 */
	public function resolve( Identity $identity, $context = null ) {
		$cfg = $this->settings->get( 'users' );
		if ( is_array( $context ) && array_key_exists( 'auto_provision', $context ) ) {
			$cfg['auto_provision'] = (bool) $context['auto_provision'];
		}

		// 1. Existing link by provider + subject id.
		$user = $this->find_by_link( $identity->provider, $identity->sub );
		if ( $user ) {
			return $user;
		}

		// 2. Link by verified email.
		if ( ! empty( $cfg['link_by_email'] ) && '' !== $identity->email && $identity->email_verified ) {
			$existing = get_user_by( 'email', $identity->email );
			if ( $existing ) {
				$this->store_link( $existing->ID, $identity );
				return $existing;
			}
		}

		// 3. Auto-provision.
		if ( empty( $cfg['auto_provision'] ) ) {
			return new \WP_Error( 'autorizenter_no_account', __( 'No matching account exists and self-registration is disabled.', 'autorizenter' ), array( 'status' => 403 ) );
		}

		return $this->provision( $identity, $cfg );
	}

	/**
	 * Create a new user from an identity.
	 *
	 * @param Identity $identity Identity.
	 * @param array    $cfg      User config.
	 * @return \WP_User|\WP_Error
	 */
	private function provision( Identity $identity, array $cfg ) {
		$email = $identity->email;
		if ( '' === $email ) {
			// Some providers (e.g. LINE without email permission) yield no email.
			$email = $identity->provider . '_' . substr( md5( $identity->sub ), 0, 12 ) . '@users.noreply.invalid';
		}

		$base     = $this->username_from( $identity, $email );
		$username = $base;
		$i        = 1;
		while ( username_exists( $username ) ) {
			$username = $base . $i;
			++$i;
		}

		$role = $this->resolve_role( $identity, $cfg );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $username,
				'user_email'   => $email,
				'display_name' => '' !== $identity->name ? $identity->name : $username,
				'user_pass'    => wp_generate_password( 32, true, true ),
				'role'         => $role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$user = get_user_by( 'id', $user_id );
		$this->store_link( $user_id, $identity );

		/**
		 * Fires when a new user is provisioned via Autorizenter.
		 *
		 * @param \WP_User $user     New user.
		 * @param Identity $identity Source identity.
		 */
		do_action( 'autorizenter_user_provisioned', $user, $identity );

		return $user;
	}

	/**
	 * Resolve the role for a new user from the configured role map.
	 *
	 * Each rule is a matcher mapped to a role; the first match wins, else the
	 * default role is used. Matchers: "domain:example.org", "provider:google",
	 * "email:a@b.com", or "*" (catch-all).
	 *
	 * @param Identity $identity Identity.
	 * @param array    $cfg      User config.
	 * @return string
	 */
	public function resolve_role( Identity $identity, array $cfg ) {
		$default = isset( $cfg['default_role'] ) && '' !== $cfg['default_role'] ? $cfg['default_role'] : 'subscriber';
		$map     = isset( $cfg['role_map'] ) && is_array( $cfg['role_map'] ) ? $cfg['role_map'] : array();

		$role = $default;
		foreach ( $map as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['match'] ) || empty( $rule['role'] ) ) {
				continue;
			}
			if ( $this->role_match( (string) $rule['match'], $identity ) ) {
				$role = sanitize_key( $rule['role'] );
				break;
			}
		}

		/**
		 * Filter the role assigned to a newly provisioned user.
		 *
		 * @param string   $role     Resolved role.
		 * @param Identity $identity Identity.
		 */
		return apply_filters( 'autorizenter_provision_role', $role, $identity );
	}

	/**
	 * Whether a role-map matcher applies to an identity.
	 *
	 * @param string   $matcher  Matcher string.
	 * @param Identity $identity Identity.
	 * @return bool
	 */
	private function role_match( $matcher, Identity $identity ) {
		$matcher = trim( $matcher );
		if ( '*' === $matcher ) {
			return true;
		}
		$parts = explode( ':', $matcher, 2 );
		if ( count( $parts ) !== 2 ) {
			return false;
		}
		$type  = strtolower( trim( $parts[0] ) );
		$value = strtolower( trim( $parts[1] ) );

		switch ( $type ) {
			case 'provider':
				return $identity->provider === $value;
			case 'email':
				return $identity->email === $value;
			case 'domain':
				$domain = $identity->email_domain();
				return '' !== $domain && ( $domain === $value || substr( $domain, - ( strlen( $value ) + 1 ) ) === '.' . $value );
			default:
				return false;
		}
	}

	/**
	 * Find a user previously linked to this provider+sub.
	 *
	 * @param string $provider Provider id.
	 * @param string $sub      Subject id.
	 * @return \WP_User|null
	 */
	private function find_by_link( $provider, $sub ) {
		if ( '' === $sub ) {
			return null;
		}
		$users = get_users(
			array(
				'meta_key'   => self::META_LINK_PREFIX . $provider, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $sub, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 1,
				'fields'     => 'all',
			)
		);
		return $users ? $users[0] : null;
	}

	/**
	 * Persist the provider link on the user.
	 *
	 * @param int      $user_id  User id.
	 * @param Identity $identity Identity.
	 * @return void
	 */
	private function store_link( $user_id, Identity $identity ) {
		update_user_meta( $user_id, self::META_LINK_PREFIX . $identity->provider, $identity->sub );
	}

	/**
	 * Derive a username candidate.
	 *
	 * @param Identity $identity Identity.
	 * @param string   $email    Email.
	 * @return string
	 */
	private function username_from( Identity $identity, $email ) {
		$local = strstr( $email, '@', true );
		$base  = sanitize_user( $local ? $local : ( $identity->provider . '_' . $identity->sub ), true );
		$base  = $base ? $base : 'user_' . substr( md5( $identity->sub . $identity->provider ), 0, 8 );
		return $base;
	}
}
