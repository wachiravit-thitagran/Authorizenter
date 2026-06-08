<?php
/**
 * Per-user / per-domain access lists (approved / blocked / pending).
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

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
	 * @param Identity $identity Identity.
	 * @return true|\WP_Error
	 */
	public function evaluate( Identity $identity ) {
		$email = $identity->email;

		if ( '' !== $email && $this->matches( $email, $this->entries( 'blocked' ) ) ) {
			return new \WP_Error( 'autorizenter_blocked', __( 'Your account has been blocked from this site.', 'autorizenter' ), array( 'status' => 403 ) );
		}

		if ( $this->is_enforced() ) {
			if ( '' === $email || ! $this->matches( $email, $this->entries( 'approved' ) ) ) {
				if ( '' !== $email ) {
					$this->add_pending( $email );
				}
				return new \WP_Error( 'autorizenter_not_approved', __( 'Your account is awaiting approval to access this site.', 'autorizenter' ), array( 'status' => 403 ) );
			}
		}

		return true;
	}

	/**
	 * Add an email to the pending list (deduplicated).
	 *
	 * @param string $email Email.
	 * @return void
	 */
	public function add_pending( $email ) {
		$email = $this->normalize( $email );
		if ( '' === $email ) {
			return;
		}
		$all     = $this->settings->all();
		$pending = isset( $all['access']['pending'] ) ? (array) $all['access']['pending'] : array();
		$pending = array_map( array( $this, 'normalize' ), $pending );
		if ( ! in_array( $email, $pending, true ) && ! $this->matches( $email, $this->entries( 'approved' ) ) ) {
			$pending[]                = $email;
			$all['access']['pending'] = array_values( array_unique( array_filter( $pending ) ) );
			$this->settings->save( $all );
		}
	}

	/**
	 * Approve emails: add to approved, remove from pending/blocked.
	 *
	 * @param string[] $emails Emails to approve.
	 * @return void
	 */
	public function approve( array $emails ) {
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
		$this->settings->save( $all );
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
