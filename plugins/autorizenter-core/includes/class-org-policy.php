<?php
/**
 * Organization access policy.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether an authenticated identity is allowed to sign in.
 *
 * Layers (all configurable, nothing hardcoded to any org):
 *  - Trusted providers: identities from your org IdP are allowed outright.
 *  - Email-domain allowlist: email must end with an allowed domain.
 *  - Verified email requirement.
 *  - Google `hd` claim requirement (Workspace).
 */
class Org_Policy {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Access lists (approved/blocked/pending).
	 *
	 * @var Access_List
	 */
	private $access;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->access   = new Access_List( $settings );
	}

	/**
	 * The configured allowed domains (lowercased), filterable.
	 *
	 * @param array|null $source Optional resolved context to read domains from.
	 * @return string[]
	 */
	public function allowed_domains( $source = null ) {
		if ( is_array( $source ) ) {
			$domains = isset( $source['allowed_domains'] ) ? (array) $source['allowed_domains'] : array();
		} else {
			$policy  = $this->settings->get( 'policy' );
			$domains = isset( $policy['allowed_domains'] ) ? (array) $policy['allowed_domains'] : array();
		}
		$domains = array_filter( array_map( array( $this, 'normalize_domain' ), $domains ) );

		/**
		 * Filter the list of allowed email domains.
		 *
		 * @param string[] $domains Allowed domains.
		 */
		return apply_filters( 'autorizenter_allowed_domains', array_values( array_unique( $domains ) ) );
	}

	/**
	 * Evaluate policy for an identity.
	 *
	 * @param Identity   $identity Identity to check.
	 * @param array|null $context  Optional resolved context to use instead of the
	 *                             global policy (see Settings::get_context()).
	 * @return true|\WP_Error True if allowed, WP_Error otherwise.
	 */
	public function is_allowed( Identity $identity, $context = null ) {
		if ( is_array( $context ) ) {
			$policy  = $context;
			$enabled = ! empty( $context['policy_enabled'] );
		} else {
			$policy  = $this->settings->get( 'policy' );
			$enabled = ! empty( $policy['enabled'] );
		}

		// Trusted providers bypass both domain checks and the approved-list gate,
		// but blocked entries are always enforced regardless of trust.
		$trusted = isset( $policy['trusted_providers'] ) ? (array) $policy['trusted_providers'] : array();

		$access = $this->access->evaluate( $identity, $trusted );
		if ( is_wp_error( $access ) ) {
			return $access;
		}

		// Organization policy is opt-in. When disabled, allow any authenticated
		// user (per-context capability checks still apply separately).
		if ( ! $enabled ) {
			/** This filter still lets integrators deny access programmatically. */
			return apply_filters( 'autorizenter_is_allowed', true, $identity );
		}

		// 1. Trusted providers bypass domain checks (e.g. your own org IdP).
		$trusted_ok = in_array( $identity->provider, $trusted, true );

		if ( ! $trusted_ok ) {
			$domains = $this->allowed_domains( is_array( $context ) ? $context : null );

			// 2. Verified email requirement (only enforced when domains gate is active).
			if ( ! empty( $domains ) ) {
				if ( ! empty( $policy['require_verified_email'] ) && ! $identity->email_verified ) {
					return $this->deny( $policy, 'email_unverified' );
				}

				$domain = $identity->email_domain();
				if ( '' === $domain || ! $this->domain_matches( $domain, $domains ) ) {
					return $this->deny( $policy, 'domain' );
				}
			}

			// 3. Google Workspace hd claim requirement.
			if ( ! empty( $policy['require_google_hd'] ) && 'google' === $identity->provider ) {
				if ( '' === $identity->hd || ! $this->domain_matches( $identity->hd, $domains ? $domains : array( $identity->hd ) ) ) {
					return $this->deny( $policy, 'hd' );
				}
			}
		}

		$result = true;

		/**
		 * Final say on whether an identity may sign in.
		 *
		 * Return a WP_Error to deny.
		 *
		 * @param true|\WP_Error $result   Current decision.
		 * @param Identity       $identity The identity.
		 */
		return apply_filters( 'autorizenter_is_allowed', $result, $identity );
	}

	/**
	 * Check whether a resolved user satisfies a context's required capability.
	 *
	 * Uses WordPress capabilities (not role names) so it works with custom roles,
	 * multisite super admins, and plugin-added capabilities.
	 *
	 * @param \WP_User $user    Resolved user.
	 * @param array    $context Resolved context (see Settings::get_context()).
	 * @return true|\WP_Error
	 */
	public function check_capability( \WP_User $user, array $context ) {
		$cap = isset( $context['required_capability'] ) && '' !== $context['required_capability']
			? $context['required_capability']
			: 'read';

		$ok = user_can( $user, $cap );

		/**
		 * Filter the per-context capability decision.
		 *
		 * @param bool     $ok      Whether the user satisfies the requirement.
		 * @param \WP_User $user    The user.
		 * @param array    $context Resolved context.
		 */
		$ok = apply_filters( 'autorizenter_context_capability', $ok, $user, $context );

		if ( $ok ) {
			return true;
		}

		return new \WP_Error(
			'autorizenter_insufficient_capability',
			__( 'Your account does not have permission to sign in here.', 'autorizenter' ),
			array(
				'status'   => 403,
				'reason'   => 'capability',
				'required' => $cap,
			)
		);
	}

	/**
	 * Build a denial error.
	 *
	 * @param array  $policy Policy config.
	 * @param string $reason Reason code.
	 * @return \WP_Error
	 */
	private function deny( $policy, $reason ) {
		$message = isset( $policy['block_message'] ) && '' !== $policy['block_message']
			? $policy['block_message']
			: __( 'Your account is not permitted to sign in to this site.', 'autorizenter' );
		return new \WP_Error(
			'autorizenter_denied',
			$message,
			array(
				'status' => 403,
				'reason' => $reason,
			)
		);
	}

	/**
	 * Does a domain match the allowlist (exact or subdomain)?
	 *
	 * @param string   $domain  Candidate domain.
	 * @param string[] $allowed Allowed domains.
	 * @return bool
	 */
	private function domain_matches( $domain, array $allowed ) {
		$domain = $this->normalize_domain( $domain );
		foreach ( $allowed as $a ) {
			if ( $domain === $a || ( '' !== $a && substr( $domain, - ( strlen( $a ) + 1 ) ) === '.' . $a ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize a domain string.
	 *
	 * @param string $domain Domain.
	 * @return string
	 */
	private function normalize_domain( $domain ) {
		$domain = strtolower( trim( (string) $domain ) );
		$domain = preg_replace( '/^@/', '', $domain );
		return $domain;
	}
}
