<?php
/**
 * Brute-force protection: throttle repeated failed logins by IP.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Counts failed login attempts per client IP and locks further attempts for a
 * progressively increasing window once a threshold is exceeded.
 *
 * Only affects the interactive login form (the `authenticate` filter); the SSO
 * flow and cookie auth are unaffected.
 */
class Login_Throttle {

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
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'authenticate', array( $this, 'maybe_block' ), 25, 1 );
		add_action( 'wp_login_failed', array( $this, 'record_failure' ) );
		add_action( 'wp_login', array( $this, 'clear' ), 10, 0 );
	}

	/**
	 * Configuration.
	 *
	 * @return array
	 */
	private function config() {
		$c = $this->settings->get( 'throttle' );
		return array(
			'enabled'         => ! empty( $c['enabled'] ),
			'max_attempts'    => isset( $c['max_attempts'] ) ? max( 1, (int) $c['max_attempts'] ) : 5,
			'lockout_seconds' => isset( $c['lockout_seconds'] ) ? max( 30, (int) $c['lockout_seconds'] ) : 900,
		);
	}

	/**
	 * Best-effort client IP.
	 *
	 * @return string
	 */
	public function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$ip = preg_replace( '/[^0-9a-f:.]/i', '', (string) $ip );
		return $ip;
	}

	/**
	 * Transient key for an IP's attempt record.
	 *
	 * @param string $ip IP.
	 * @return string
	 */
	private function key( $ip ) {
		return 'authorizenter_lockout_' . md5( $ip );
	}

	/**
	 * Current attempt record for an IP.
	 *
	 * @param string $ip IP.
	 * @return array { count:int, locked_until:int }
	 */
	private function record( $ip ) {
		$r = get_transient( $this->key( $ip ) );
		if ( ! is_array( $r ) ) {
			$r = array(
				'count'        => 0,
				'locked_until' => 0,
			);
		}
		return $r;
	}

	/**
	 * Timestamp until which an IP is locked out (0 if not locked).
	 *
	 * @param string $ip IP.
	 * @return int
	 */
	public function lockout_until( $ip ) {
		$r = $this->record( $ip );
		return isset( $r['locked_until'] ) ? (int) $r['locked_until'] : 0;
	}

	/**
	 * Whether an IP is currently locked out.
	 *
	 * @param string $ip IP.
	 * @return bool
	 */
	public function is_locked( $ip ) {
		$cfg = $this->config();
		if ( ! $cfg['enabled'] ) {
			return false;
		}
		$r = $this->record( $ip );
		return ! empty( $r['locked_until'] ) && $r['locked_until'] > time();
	}

	/**
	 * Block authentication while the client IP is locked out.
	 *
	 * @param null|\WP_User|\WP_Error $user Authentication result so far.
	 * @return null|\WP_User|\WP_Error
	 */
	public function maybe_block( $user ) {
		$cfg = $this->config();
		if ( ! $cfg['enabled'] ) {
			return $user;
		}
		if ( $this->is_locked( $this->client_ip() ) ) {
			return new \WP_Error(
				'authorizenter_locked_out',
				__( 'Too many failed login attempts. Please try again later.', 'authorizenter' )
			);
		}
		return $user;
	}

	/**
	 * Record a failed login and lock out after the threshold.
	 *
	 * @return void
	 */
	public function record_failure() {
		$cfg = $this->config();
		if ( ! $cfg['enabled'] ) {
			return;
		}
		$ip = $this->client_ip();
		$r  = $this->record( $ip );
		++$r['count'];

		$ttl = $cfg['lockout_seconds'];
		if ( $r['count'] >= $cfg['max_attempts'] ) {
			// Progressive: each lockout past the threshold lasts longer.
			$over              = $r['count'] - $cfg['max_attempts'] + 1;
			$ttl               = $cfg['lockout_seconds'] * $over;
			$r['locked_until'] = time() + $ttl;
		}

		set_transient( $this->key( $ip ), $r, $ttl );
	}

	/**
	 * Clear the record for the current IP on successful login.
	 *
	 * @return void
	 */
	public function clear() {
		delete_transient( $this->key( $this->client_ip() ) );
	}
}
