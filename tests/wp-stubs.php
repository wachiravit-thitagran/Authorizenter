<?php
/**
 * Minimal WordPress function/class stubs for unit testing Autorizenter Core
 * without a full WordPress install. Backed by in-memory global state that tests
 * can manipulate via the helpers near the bottom.
 *
 * @package Autorizenter\Core\Tests
 */

// phpcs:disable

define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'AUTH_KEY' ) ) {
	define( 'AUTH_KEY', 'unit-test-auth-key' );
}
if ( ! defined( 'AUTH_SALT' ) ) {
	define( 'AUTH_SALT', 'unit-test-auth-salt' );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

$GLOBALS['__options']    = array();
$GLOBALS['__usermeta']   = array();
$GLOBALS['__users']      = array();
$GLOBALS['__transients'] = array();
$GLOBALS['__next_uid']   = 100;

/** Reset all in-memory state between tests. */
function azr_test_reset() {
	$GLOBALS['__options']    = array();
	$GLOBALS['__usermeta']   = array();
	$GLOBALS['__users']      = array();
	$GLOBALS['__transients'] = array();
	$GLOBALS['__next_uid']   = 100;
}

/** Register a fake user. */
function azr_test_make_user( $id, array $caps = array( 'read' => true ), $email = '', $name = '' ) {
	$u                = new WP_User();
	$u->ID            = $id;
	$u->caps          = $caps;
	$u->user_email    = '' !== $email ? $email : 'user' . $id . '@example.test';
	$u->display_name  = '' !== $name ? $name : 'User ' . $id;
	$u->user_login    = 'user' . $id;
	$GLOBALS['__users'][ $id ] = $u;
	return $u;
}

// --- Error / user objects ---------------------------------------------------

class WP_Error {
	protected $code;
	protected $message;
	protected $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
	public function add_data( $data ) { $this->data = $data; }
}

class WP_User {
	public $ID = 0;
	public $caps = array();
	public $user_email = '';
	public $display_name = '';
	public $user_login = '';
	public $role = '';
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

// --- Options ----------------------------------------------------------------

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['__options'] ) ? $GLOBALS['__options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['__options'][ $key ] = $value;
	return true;
}
function delete_option( $key ) {
	unset( $GLOBALS['__options'][ $key ] );
	return true;
}

// --- Transients -------------------------------------------------------------

function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['__transients'] ) ? $GLOBALS['__transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['__transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['__transients'][ $key ] );
	return true;
}

// --- User meta --------------------------------------------------------------

function get_user_meta( $uid, $key = '', $single = false ) {
	$m = isset( $GLOBALS['__usermeta'][ $uid ][ $key ] ) ? $GLOBALS['__usermeta'][ $uid ][ $key ] : '';
	return $single ? $m : ( '' === $m ? array() : array( $m ) );
}
function update_user_meta( $uid, $key, $value ) {
	$GLOBALS['__usermeta'][ $uid ][ $key ] = $value;
	return true;
}

// --- Users ------------------------------------------------------------------

function user_can( $user, $cap ) {
	$id = is_object( $user ) ? $user->ID : (int) $user;
	if ( isset( $GLOBALS['__users'][ $id ] ) ) {
		return ! empty( $GLOBALS['__users'][ $id ]->caps[ $cap ] );
	}
	return is_object( $user ) && ! empty( $user->caps[ $cap ] );
}

function get_userdata( $id ) {
	return isset( $GLOBALS['__users'][ $id ] ) ? $GLOBALS['__users'][ $id ] : false;
}

function get_user_by( $field, $value ) {
	foreach ( $GLOBALS['__users'] as $u ) {
		if ( 'id' === $field && (int) $u->ID === (int) $value ) {
			return $u;
		}
		if ( 'email' === $field && strtolower( $u->user_email ) === strtolower( (string) $value ) ) {
			return $u;
		}
		if ( 'login' === $field && $u->user_login === $value ) {
			return $u;
		}
	}
	return false;
}

function username_exists( $login ) {
	$u = get_user_by( 'login', $login );
	return $u ? $u->ID : false;
}

function get_users( $args = array() ) {
	$key = isset( $args['meta_key'] ) ? $args['meta_key'] : null;
	$val = array_key_exists( 'meta_value', $args ) ? $args['meta_value'] : null;
	$out = array();
	foreach ( $GLOBALS['__users'] as $id => $u ) {
		if ( null !== $key ) {
			$um = isset( $GLOBALS['__usermeta'][ $id ] ) ? $GLOBALS['__usermeta'][ $id ] : array();
			if ( ! array_key_exists( $key, $um ) ) {
				continue;
			}
			if ( null !== $val && (string) $um[ $key ] !== (string) $val ) {
				continue;
			}
		}
		$out[] = $u;
	}
	if ( isset( $args['number'] ) ) {
		$out = array_slice( $out, 0, (int) $args['number'] );
	}
	return $out;
}

function wp_insert_user( $data ) {
	$id              = $GLOBALS['__next_uid']++;
	$u               = new WP_User();
	$u->ID           = $id;
	$u->user_login   = isset( $data['user_login'] ) ? $data['user_login'] : 'user' . $id;
	$u->user_email   = isset( $data['user_email'] ) ? $data['user_email'] : '';
	$u->display_name = isset( $data['display_name'] ) ? $data['display_name'] : $u->user_login;
	$u->role         = isset( $data['role'] ) ? $data['role'] : '';
	$u->caps         = array( 'read' => true );
	$GLOBALS['__users'][ $id ] = $u;
	return $id;
}

function wp_generate_password( $len = 12, $special = true, $extra = false ) {
	return substr( str_repeat( 'aA1!', (int) ceil( $len / 4 ) ), 0, $len );
}

function sanitize_user( $username, $strict = false ) {
	$username = strtolower( (string) $username );
	return preg_replace( '/[^a-z0-9_\.\-@]/', '', $username );
}

// --- $wpdb mock -------------------------------------------------------------

class WPDB_Stub {
	public $usermeta = 'wp_usermeta';
	public $users    = 'wp_users';
	public $options  = 'wp_options';

	public function prepare( $query, ...$args ) {
		foreach ( $args as $a ) {
			$rep   = is_int( $a ) || is_float( $a ) ? (string) $a : "'" . addslashes( (string) $a ) . "'";
			$query = preg_replace( '/%[sdf]/', $rep, $query, 1 );
		}
		return $query;
	}

	public function get_results( $query ) {
		// Emulate: SELECT user_id, meta_value FROM wp_usermeta WHERE meta_key = 'X'.
		if ( preg_match( "/meta_key = '([^']*)'/", $query, $m ) ) {
			$key = $m[1];
			$out = array();
			foreach ( $GLOBALS['__usermeta'] as $uid => $meta ) {
				if ( array_key_exists( $key, $meta ) ) {
					$out[] = (object) array( 'user_id' => $uid, 'meta_value' => (string) $meta[ $key ] );
				}
			}
			return $out;
		}
		return array();
	}

	public function query( $query ) {
		return 0;
	}
}

$GLOBALS['wpdb'] = new WPDB_Stub();

// --- Hooks (passthrough) ----------------------------------------------------

function apply_filters( $tag, $value = null ) { return $value; }
function do_action() {}
function add_filter() { return true; }
function add_action() { return true; }

// --- i18n / escaping / sanitizers (passthrough-ish) -------------------------

function __( $text, $domain = null ) { return $text; }
function esc_html__( $text, $domain = null ) { return $text; }
function esc_url_raw( $url ) { return $url; }
function esc_url( $url ) { return $url; }
function esc_attr( $s ) { return $s; }
function sanitize_key( $key ) {
	$key = strtolower( (string) $key );
	return preg_replace( '/[^a-z0-9_\-]/', '', $key );
}
function sanitize_text_field( $s ) { return trim( (string) $s ); }
function sanitize_textarea_field( $s ) { return trim( (string) $s ); }
function wpautop( $s ) { return $s; }
function wp_kses_post( $s ) { return $s; }

// --- Plugin / URL helpers ---------------------------------------------------

function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }

function wp_unslash( $value ) {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function get_plugin_data( $file, $markup = true, $translate = true ) {
	return array(
		'Name'        => 'Autorizenter Core',
		'Description' => 'Test description.',
		'Author'      => 'Autorizenter contributors',
		'Version'     => '0.1.0',
	);
}

function home_url( $path = '/' ) {
	return 'https://example.test' . $path;
}

function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' );
}

function wp_validate_redirect( $location, $fallback = '' ) {
	$location = trim( (string) $location );
	if ( '' === $location ) {
		return $fallback;
	}
	if ( '/' === $location[0] ) {
		return $location; // relative, same site.
	}
	$host = wp_parse_url( $location, PHP_URL_HOST );
	$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
	return ( $host === $home ) ? $location : $fallback;
}

function add_query_arg( $args, $url = '' ) {
	$query = http_build_query( is_array( $args ) ? $args : array( $args => '' ) );
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $query;
}

// phpcs:enable
