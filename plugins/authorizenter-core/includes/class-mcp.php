<?php
/**
 * MCP / WordPress Abilities integration.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

final class MCP {
	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	public static function register_category() {
		wp_register_ability_category(
			'authorizenter',
			array(
				'label'       => 'Authorizenter',
				'description' => 'Authentication providers, identity diagnostics and reports.',
			)
		);
	}

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
						'context' => array( 'type' => 'string', 'default' => 'default' ),
					),
				),
				'execute_callback'    => array( __CLASS__, 'list_providers' ),
				'permission_callback' => static function () { return current_user_can( 'read' ); },
				'meta'                => self::meta(),
			)
		);

		wp_register_ability(
			'authorizenter/get-provider-data',
			array(
				'label'       => 'Get Authorizenter Provider Data',
				'description' => 'Return stored identity data for a WordPress user.',
				'category'    => 'authorizenter',
				'input_schema' => array(
					'type'       => 'object',
					'properties' => array(
						'user_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
						'provider_id' => array( 'type' => 'string' ),
					),
					'required' => array( 'user_id' ),
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
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'execute_callback'    => array( __CLASS__, 'get_answer_report' ),
				'permission_callback' => static function () { return current_user_can( 'list_users' ); },
				'meta'                => self::meta(),
			)
		);
	}

	public static function can_read_user( array $input ) {
		$user_id = (int) $input['user_id'];
		return get_current_user_id() === $user_id || current_user_can( 'list_users' );
	}

	public static function list_providers( array $input ) {
		$request = new \WP_REST_Request( 'GET', '/' . AUTHORIZENTER_REST_NAMESPACE . '/providers' );
		$request->set_param( 'context', isset( $input['context'] ) ? (string) $input['context'] : 'default' );
		return self::rest_data( rest_do_request( $request ) );
	}

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

	public static function get_answer_report() {
		$request = new \WP_REST_Request( 'GET', '/' . AUTHORIZENTER_REST_NAMESPACE . '/answers/report' );
		return self::rest_data( rest_do_request( $request ) );
	}

	private static function rest_data( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return $response instanceof \WP_REST_Response ? $response->get_data() : $response;
	}

	private static function meta() {
		return array(
			'mcp' => array( 'public' => true, 'type' => 'tool' ),
			'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true, 'openWorldHint' => false ),
		);
	}
}
