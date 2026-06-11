<?php
/**
 * Per-user / per-domain access lists (approved / blocked / pending).
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Authorizer-style access control by individual email or domain.
 *
 * - Blocked entries are always denied, regardless of any other setting.
 * - When enforcement is on, only approved identities may sign in; everyone else is
 *   denied and recorded in the pending list for an administrator to review.
 *
 * List entries may be a full email ("user@example.org") or a domain
 * ("example.org" or "@example.org"). Domain entries match that domain and its
 * subdomains.
 */
class Access_List {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings store.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Whether approved-list enforcement is on.
	 *
	 * @return bool
	 */
	public function is_enforced() {
		$access = $this->settings->get( 'access' );
		return ! empty( $access['enabled'] );
	}

	/**
	 * Normalised entries for a list.
	 *
	 * @param string $list approved|blocked|pending.
	 * @return string[]
	 */
	public function entries( $list ) {
		$access = $this->settings->get( 'access' );
		$items  = isset( $access[ $list ] ) ? (array) $access[ $list ] : array();
		$items  = array_map( array( $this, 'normalize' ), $items );
		return array_values( array_unique( array_filter( $items ) ) );
	}

	/**
	 * Evaluate an identity against the lists.
	 *
	 * Blocked entries are always enforced. Approved-list enforcement is skipped for
	 * providers listed in $trusted_providers (they are allowed without needing approval).
	 *
	 * @param Identity $identity          Identity.
	 * @param string[] $trusted_providers Provider IDs whose users bypass approval enforcement.
	 * @return true|\WP_Error
	 */
	public function evaluate( Identity $identity, array $trusted_providers = array() ) {
		$email = $identity->email;

		if ( '' !== $email && $this->matches( $email, $this->entries( 'blocked' ) ) ) {
			return new \WP_Error( 'authorizenter_blocked', __( 'Your account has been blocked from this site.', 'authorizenter' ), array( 'status' => 403 ) );
		}

		$is_trusted = in_array( $identity->provider, $trusted_providers, true );

		if ( ! $is_trusted && $this->is_enforced() ) {
			// An existing WordPress account was already vetted when it was created,
			// so (when enabled) it may sign in without going through approval again.
			if ( '' !== $email && $this->existing_account_bypass( $identity ) ) {
				return true;
			}

			if ( '' === $email || ! $this->matches( $email, $this->entries( 'approved' ) ) ) {
				$token = '';
				if ( '' !== $email ) {
					$name  = trim( $identity->first_name . ' ' . $identity->last_name );
					$token = $this->add_pending(
						$email,
						array(
							'provider' => $identity->provider,
							'name'     => $name,
						)
					);
				}
				return new \WP_Error(
					'authorizenter_not_approved',
					__( 'Your account is awaiting approval to access this site.', 'authorizenter' ),
					array(
						'status'        => 403,
						'pending_token' => $token,
						'provider'      => $identity->provider,
					)
				);
			}
		}

		return true;
	}

	/**
	 * Whether an identity with an existing WordPress account skips the approval
	 * gate.
	 *
	 * Controlled by the "Existing accounts" setting (access.allow_existing,
	 * defaulting to on) and overridable via the
	 * authorizenter_existing_account_skips_approval filter. Blocked entries are
	 * checked before this and always win.
	 *
	 * @param Identity $identity Identity.
	 * @return bool
	 */
	private function existing_account_bypass( Identity $identity ) {
		$access = $this->settings->get( 'access' );
		$allow  = ! isset( $access['allow_existing'] ) || ! empty( $access['allow_existing'] );

		/**
		 * Filter whether an already-registered WordPress account bypasses approval.
		 *
		 * @param bool     $allow    Current setting.
		 * @param Identity $identity Identity attempting to sign in.
		 */
		$allow = (bool) apply_filters( 'authorizenter_existing_account_skips_approval', $allow, $identity );
		if ( ! $allow ) {
			return false;
		}

		return (bool) get_user_by( 'email', $identity->email );
	}

	/**
	 * Add an email to the pending list (deduplicated).
	 *
	 * Stores a short-lived token so the user can submit pre-approval answers.
	 *
	 * @param string $email Email.
	 * @param array  $meta  Optional metadata: provider, name.
	 * @return string One-time token (empty string when email is blank).
	 */
	public function add_pending( $email, array $meta = array() ) {
		$email = $this->normalize( $email );
		if ( '' === $email ) {
			return '';
		}
		$all     = $this->settings->all();
		$pending = isset( $all['access']['pending'] ) ? (array) $all['access']['pending'] : array();
		$pending = array_map( array( $this, 'normalize' ), $pending );
		if ( ! in_array( $email, $pending, true ) && ! $this->matches( $email, $this->entries( 'approved' ) ) ) {
			$pending[]                = $email;
			$all['access']['pending'] = array_values( array_unique( array_filter( $pending ) ) );
			$this->settings->save( $all );
		}

		// Generate a token so the user can reach the pre-approval form.
		$token = wp_generate_password( 24, false );
		set_transient(
			'azr_pending_' . hash( 'sha256', $token ),
			array_merge( $meta, array( 'email' => $email ) ),
			HOUR_IN_SECONDS
		);
		return $token;
	}

	/**
	 * The provider associated with a pending token (for scoping the pre-approval
	 * questions to that provider).
	 *
	 * @param string $token Pending token from add_pending().
	 * @return string Provider id, or '' when unknown.
	 */
	public function pending_provider( $token ) {
		$data = get_transient( 'azr_pending_' . hash( 'sha256', (string) $token ) );
		return is_array( $data ) && isset( $data['provider'] ) ? (string) $data['provider'] : '';
	}

	/**
	 * Save pre-approval answers for a pending identity.
	 *
	 * @param string $token   Pending token from add_pending().
	 * @param array  $answers Map of question_id => answer.
	 * @return true|\WP_Error
	 */
	public function save_pending_answers( $token, array $answers ) {
		$key  = 'azr_pending_' . hash( 'sha256', (string) $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ! isset( $data['email'] ) ) {
			return new \WP_Error( 'authorizenter_invalid_token', __( 'Invalid or expired token.', 'authorizenter' ), array( 'status' => 400 ) );
		}
		$email = $data['email'];
		$all   = $this->settings->all();

		$meta = isset( $all['access']['pending_meta'] ) && is_array( $all['access']['pending_meta'] )
			? $all['access']['pending_meta']
			: array();
		if ( ! isset( $meta[ $email ] ) ) {
			$meta[ $email ] = array();
		}
		// Preserve provider/name, update answers.
		$meta[ $email ]['provider'] = isset( $data['provider'] ) ? (string) $data['provider'] : '';
		$meta[ $email ]['name']     = isset( $data['name'] ) ? (string) $data['name'] : '';
		$meta[ $email ]['answers']  = $answers;

		$all['access']['pending_meta'] = $meta;
		$this->settings->save( $all );
		return true;
	}

	/**
	 * Get metadata for all pending identities.
	 *
	 * @return array Keyed by normalized email.
	 */
	public function get_pending_meta() {
		$all = $this->settings->all();
		return isset( $all['access']['pending_meta'] ) && is_array( $all['access']['pending_meta'] )
			? $all['access']['pending_meta']
			: array();
	}

	/**
	 * Approve emails: add to approved, remove from pending/blocked.
	 *
	 * @param string[]              $emails Emails to approve.
	 * @param array<string,string>  $roles  Optional email => role slug to assign
	 *                                       that user when they are provisioned.
	 * @return void
	 */
	public function approve( array $emails, array $roles = array() ) {
		$emails = array_filter( array_map( array( $this, 'normalize' ), $emails ) );
		if ( empty( $emails ) ) {
			return;
		}
		$all      = $this->settings->all();
		$approved = isset( $all['access']['approved'] ) ? array_map( array( $this, 'normalize' ), (array) $all['access']['approved'] ) : array();
		$pending  = isset( $all['access']['pending'] ) ? array_map( array( $this, 'normalize' ), (array) $all['access']['pending'] ) : array();

		$approved = array_merge( $approved, $emails );
		$pending  = array_diff( $pending, $emails );

		$all['access']['approved'] = array_values( array_unique( array_filter( $approved ) ) );
		$all['access']['pending']  = array_values( $pending );

		// Per-email role assignment (used when the user is provisioned on login).
		$approved_roles = isset( $all['access']['approved_roles'] ) && is_array( $all['access']['approved_roles'] ) ? $all['access']['approved_roles'] : array();
		foreach ( $roles as $r_email => $r_role ) {
			$r_email = $this->normalize( $r_email );
			$r_role  = sanitize_key( $r_role );
			if ( '' !== $r_email && '' !== $r_role ) {
				$approved_roles[ $r_email ] = $r_role;
			}
		}
		$all['access']['approved_roles'] = $approved_roles;

		// Clear stored metadata for approved identities.
		if ( isset( $all['access']['pending_meta'] ) && is_array( $all['access']['pending_meta'] ) ) {
			foreach ( $emails as $email ) {
				unset( $all['access']['pending_meta'][ $email ] );
			}
		}

		$this->settings->save( $all );
	}

	/**
	 * After a user is provisioned, drop their exact email from the approved list to
	 * keep it clean — but only when existing WordPress accounts skip approval, so
	 * they are not parked in pending again on their next login. Domain entries and
	 * approvals matched by domain are left untouched.
	 *
	 * @param string $email Provisioned user's email.
	 * @return void
	 */
	public function release_after_provision( $email ) {
		$email = $this->normalize( $email );
		if ( '' === $email ) {
			return;
		}

		$access         = $this->settings->get( 'access' );
		$allow_existing = ! isset( $access['allow_existing'] ) || ! empty( $access['allow_existing'] );

		/**
		 * Filter whether to remove an email from the approved list once the user has
		 * a WordPress account.
		 *
		 * @param bool   $allow_existing Whether removal is safe (existing-account bypass on).
		 * @param string $email          The provisioned email.
		 */
		if ( ! (bool) apply_filters( 'authorizenter_release_approved_after_provision', $allow_existing, $email ) ) {
			return;
		}

		$all      = $this->settings->all();
		$approved = isset( $all['access']['approved'] ) ? array_map( array( $this, 'normalize' ), (array) $all['access']['approved'] ) : array();
		if ( ! in_array( $email, $approved, true ) ) {
			return; // Approved via a domain entry, not an exact email — keep the list.
		}

		$all['access']['approved'] = array_values( array_diff( $approved, array( $email ) ) );
		if ( isset( $all['access']['approved_roles'][ $email ] ) ) {
			unset( $all['access']['approved_roles'][ $email ] ); // Role is now on the user.
		}
		$this->settings->save( $all );
	}

	/**
	 * Role assigned to a specific approved email, if any.
	 *
	 * @param string $email Email.
	 * @return string Role slug, or '' when none is set.
	 */
	public function approved_role( $email ) {
		$email = $this->normalize( $email );
		$all   = $this->settings->all();
		$roles = isset( $all['access']['approved_roles'] ) && is_array( $all['access']['approved_roles'] ) ? $all['access']['approved_roles'] : array();
		return isset( $roles[ $email ] ) ? sanitize_key( $roles[ $email ] ) : '';
	}

	/**
	 * Whether an email matches any entry (exact email or domain).
	 *
	 * @param string   $email   Email.
	 * @param string[] $entries List entries.
	 * @return bool
	 */
	private function matches( $email, array $entries ) {
		$email  = strtolower( trim( (string) $email ) );
		$pos    = strrpos( $email, '@' );
		$domain = false === $pos ? '' : substr( $email, $pos + 1 );

		foreach ( $entries as $entry ) {
			if ( false !== strpos( $entry, '@' ) ) {
				if ( $entry === $email ) {
					return true; // exact email.
				}
				continue;
			}
			// Domain entry: exact or subdomain.
			if ( '' !== $domain && ( $domain === $entry || substr( $domain, - ( strlen( $entry ) + 1 ) ) === '.' . $entry ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Normalize an entry (lowercase, trim, strip a leading @ for domains).
	 *
	 * @param string $value Entry.
	 * @return string
	 */
	private function normalize( $value ) {
		$value = strtolower( trim( (string) $value ) );
		if ( '' !== $value && false === strpos( $value, '@' ) ) {
			return $value; // domain.
		}
		if ( 0 === strpos( $value, '@' ) ) {
			return substr( $value, 1 ); // Strip a leading at-sign from a domain entry.
		}
		return $value; // email.
	}
}
