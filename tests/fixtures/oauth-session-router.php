<?php
/** Local PHP HTTP session harness; never a WordPress route or release runtime. */
if ( 'cli-server' !== PHP_SAPI ) {
	exit( 1 );
}
require dirname( __DIR__ ) . '/bootstrap.php';

class HttpSessionProvider extends \Authorizenter\Core\Providers\OAuth2 {
	public function exchange( $code, $redirect_uri, $code_verifier, $nonce ) {
		return new WP_Error( 'exchange_reached', 'Synthetic exchange; no outbound network.' );
	}
}

// A file emulates the shared WP transient store across HTTP requests. Browser
// secrets still use actual PHP cookie/session persistence, separate from it.
$store = getenv( 'AUTHORIZENTER_TEST_FLOW_STORE' );
$GLOBALS['__transients'] = file_exists( $store ) ? json_decode( file_get_contents( $store ), true ) : array();
register_shutdown_function( static function () use ( $store ) {
	file_put_contents( $store, json_encode( $GLOBALS['__transients'] ), LOCK_EX );
} );
$GLOBALS['__mock_filters']['authorizenter_provider_classes'] = static function ( $classes ) {
	$classes['oauth2'] = HttpSessionProvider::class;
	return $classes;
};
update_option( \Authorizenter\Core\Settings::OPTION, array( 'providers' => array( 'test' => array(
	'type' => 'oauth2', 'enabled' => true, 'client_id' => 'test', 'authorization_endpoint' => 'https://idp.example.test/authorize',
) ) ) );
$settings = new \Authorizenter\Core\Settings();
$policy = new \Authorizenter\Core\Org_Policy( $settings );
$engine = new \Authorizenter\Core\OAuth_Engine( $settings, new \Authorizenter\Core\Provider_Registry( $settings ), $policy, new \Authorizenter\Core\User_Mapper( $settings, $policy ) );
header( 'Content-Type: application/json' );
if ( isset( $_GET['state'] ) ) {
	$result = $engine->handle_callback( 'synthetic', $_GET['state'] );
	echo json_encode( array( 'result' => $result->get_error_code() ) );
} else {
	$url = $engine->begin( 'test' );
	if ( is_wp_error( $url ) ) {
		echo json_encode( array( 'error' => $url->get_error_code() ) );
	} else {
		parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
		echo json_encode( array( 'state' => $query['state'] ) );
	}
}
