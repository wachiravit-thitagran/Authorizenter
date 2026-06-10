<?php
/**
 * Google provider preset (OIDC).
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Google is a standard OIDC IdP; we just hardwire its discovery URL so admins
 * only need to paste a Client ID / Secret. The `hd` claim is preserved for
 * Workspace domain restriction.
 */
class Google extends OIDC {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return 'google';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return isset( $this->config['label'] ) && '' !== $this->config['label']
			? (string) $this->config['label']
			: __( 'Google', 'autorizenter' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function oidc_provider_url() {
		return 'https://accounts.google.com';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function discovery() {
		// Force Google's well-known config regardless of stored discovery_url.
		$this->config['discovery_url'] = 'https://accounts.google.com/.well-known/openid-configuration';
		return parent::discovery();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function scopes() {
		return 'openid email profile';
	}
}
