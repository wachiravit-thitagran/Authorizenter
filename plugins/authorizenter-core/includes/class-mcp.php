<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Authorizenter abilities for MCP Adapter.
 */
final class MCP {

	/**
	 * Register hooks when the WordPress Abilities API is available.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the Authorizenter category.
	 *
	 * @return void
	 */
	public static function register_category() {
		wp_register_ability_category(
			'authorizenter',
			array(
				'label'       => 'Authorizenter',
				'description' => 'Authentication providers, identity diagnostics and reports.',
			)
		);
	}

	/**
	 * Register MCP-visible abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		wp_register_ability(
			'authorizenter/list-providers',
			array(
				'label'               => 'List Authorizenter Providers',
				'description'         => 'List enabled Authorizenter providers for a context.',
				'category'            => 'authorizenter',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'context' => array(
							'type'    => 'string',
							'default' => 'default',
						),
					),
				),
				'execute_callback'    => array( __CLASS__, 'list_providers' ),
				'permission_callback' => static function () {
					return current_user_can( 'read' );
				},
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'authorizenter/get-provider-data',
			array(
				'label'               => 'Get Authorizenter Provider Data',
				'description'         => 'Return stored identity data for a WordPress user.',
				'category'            => 'authorizenter',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'provider_id' => array(
							'type' => 'string',
						),
					),
					'required'   => array( 'user_id' ),
				),
				'execute_callback'    => array( __CLASS__, 'get_provider_data' ),
				'permission_callback' => array( __CLASS__, 'can_read_user' ),
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'authorizenter/get-answer-report',
			array(
				'label'               => 'Get Authorizenter Answer Report',
				'description'         => 'Return aggregate post-login question answers.',
				'category'            => 'authorizenter',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'execute_callback'    => array( __CLASS__, 'get_answer_report' ),
				'permission_callback' => static function () {
					return current_user_can( 'list_users' );
				},
				'meta'                => self::meta(),
			)
		);
	}

	/**
	 * Check whether the caller can inspect a user's provider data.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return bool
	 */
	public static function can_read_user( array $input ) {
		$user_id = (int) $input['user_id'];

		return get_current_user_id() === $user_id || current_user_can( 'list_users' );
	}

	/**
	 * List enabled providers through the plugin REST contract.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return mixed
	 */
	public static function list_providers( array $input ) {
		$request = new \WP_REST_Request( 'GET', '/' . AUTHORIZENTER_REST_NAMESPACE . '/providers' );
		$request->set_param(
			'context',
			isset( $input['context'] ) ? (string) $input['context'] : 'default'
		);

		return self::rest_data( rest_do_request( $request ) );
	}

	/**
	 * Return provider identity data through the public helper.
	 *
	 * @param array<string,mixed> $input Ability input.
	 * @return array<string,mixed>
	 */
	public static function get_provider_data( array $input ) {
		$data = authorizenter_get_provider_data(
			(int) $input['user_id'],
			isset( $input['provider_id'] ) ? sanitize_key( $input['provider_id'] ) : ''
		);

		return array(
			'user_id' => (int) $input['user_id'],
			'data'    => false === $data ? null : $data,
		);
	}

	/**
	 * Return the aggregate answer report.
	 *
	 * @return mixed
	 */
	public static function get_answer_report() {
		$request = new \WP_REST_Request( 'GET', '/' . AUTHORIZENTER_REST_NAMESPACE . '/answers/report' );

		return self::rest_data( rest_do_request( $request ) );
	}

	/**
	 * Normalize an internal REST response.
	 *
	 * @param mixed $response REST response.
	 * @return mixed
	 */
	private static function rest_data( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}

	/**
	 * Shared MCP metadata.
	 *
	 * @return array<string,mixed>
	 */
	private static function meta() {
		return array(
			'mcp'         => array(
				'public' => true,
				'type'   => 'tool',
			),
			'annotations' => array(
				'readonly'      => true,
				'destructive'   => false,
				'idempotent'    => true,
				'openWorldHint' => false,
			),
		);
	}
}
