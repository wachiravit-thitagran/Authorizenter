<?php
/**
 * LINE Login provider preset (OIDC).
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * LINE Login implements OIDC. It exposes a discovery document, so it slots into
 * the generic OIDC adapter. Note: LINE only returns an email when the channel has
 * the email permission approved and the user consents; otherwise email is empty
 * and org-domain policy cannot apply (treat LINE as an identity-only provider or
 * trust it explicitly in policy).
 */
class Line extends OIDC {

	/**
	 * {@inheritDoc}
	 */
	public function id() {
		return isset( $this->config['id'] ) ? (string) $this->config['id'] : 'line';
	}

	/**
	 * {@inheritDoc}
	 */
	public function label() {
		return isset( $this->config['label'] ) && '' !== $this->config['label']
			? (string) $this->config['label']
			: __( 'LINE', 'authorizenter' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function oidc_provider_url() {
		return 'https://access.line.me';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function discovery() {
		$this->config['discovery_url'] = 'https://access.line.me/.well-known/openid-configuration';
		return parent::discovery();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function scopes() {
		// "email" requires the email permission to be enabled for the channel.
		return 'openid profile email';
	}
}
