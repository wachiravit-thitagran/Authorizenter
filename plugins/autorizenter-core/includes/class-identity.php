<?php
/**
 * Normalized identity returned by providers.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable-ish value object representing an authenticated identity.
 */
class Identity {

	/**
	 * Provider id (e.g. "google").
	 *
	 * @var string
	 */
	public $provider;

	/**
	 * Stable subject id at the provider.
	 *
	 * @var string
	 */
	public $sub;

	/**
	 * Email address (may be empty).
	 *
	 * @var string
	 */
	public $email;

	/**
	 * Whether the provider asserts the email is verified.
	 *
	 * @var bool
	 */
	public $email_verified;

	/**
	 * Display name.
	 *
	 * @var string
	 */
	public $name;

	/**
	 * Hosted domain claim (Google Workspace), if present.
	 *
	 * @var string
	 */
	public $hd;

	/**
	 * First name from provider claims.
	 *
	 * @var string
	 */
	public $first_name;

	/**
	 * Last name from provider claims.
	 *
	 * @var string
	 */
	public $last_name;

	/**
	 * Username hint from provider claims (used by User_Mapper link_by_username).
	 *
	 * @var string
	 */
	public $username;

	/**
	 * Raw claims/profile for advanced policy via filters.
	 *
	 * @var array
	 */
	public $raw;

	/**
	 * Constructor.
	 *
	 * @param string $provider       Provider id.
	 * @param array  $data           Normalized fields.
	 */
	public function __construct( $provider, array $data ) {
		$this->provider       = $provider;
		$this->sub            = isset( $data['sub'] ) ? (string) $data['sub'] : '';
		$this->email          = isset( $data['email'] ) ? strtolower( trim( (string) $data['email'] ) ) : '';
		$this->email_verified = ! empty( $data['email_verified'] );
		$this->name           = isset( $data['name'] ) ? (string) $data['name'] : '';
		$this->hd             = isset( $data['hd'] ) ? strtolower( (string) $data['hd'] ) : '';
		$this->raw            = isset( $data['raw'] ) && is_array( $data['raw'] ) ? $data['raw'] : array();
		$this->first_name     = isset( $data['first_name'] ) ? (string) $data['first_name'] : '';
		$this->last_name      = isset( $data['last_name'] ) ? (string) $data['last_name'] : '';
		$this->username       = isset( $data['username'] ) ? (string) $data['username'] : '';
	}

	/**
	 * Email domain (portion after @), lowercased.
	 *
	 * @return string
	 */
	public function email_domain() {
		$pos = strrpos( $this->email, '@' );
		return false === $pos ? '' : substr( $this->email, $pos + 1 );
	}
}
