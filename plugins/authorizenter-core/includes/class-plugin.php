<?php
/**
 * Main plugin controller.
 *
 * @package Authorizenter\Core
 */

namespace Authorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Wires together the engine, providers, policy, questions and REST API.
 */
class Plugin {

	/**
	 * Settings store.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Provider registry.
	 *
	 * @var Provider_Registry
	 */
	public $providers;

	/**
	 * OAuth engine.
	 *
	 * @var OAuth_Engine
	 */
	public $engine;

	/**
	 * Organization policy.
	 *
	 * @var Org_Policy
	 */
	public $policy;

	/**
	 * User mapper.
	 *
	 * @var User_Mapper
	 */
	public $users;

	/**
	 * Questions manager.
	 *
	 * @var Questions
	 */
	public $questions;

	/**
	 * Reports aggregator.
	 *
	 * @var Reports
	 */
	public $reports;

	/**
	 * REST API controller.
	 *
	 * @var Rest_Api
	 */
	public $rest;

	/**
	 * Constructor: build the object graph and register hooks.
	 */
	public function __construct() {
		$this->settings  = new Settings();
		$this->providers = new Provider_Registry( $this->settings );
		$this->policy    = new Org_Policy( $this->settings );
		$this->users     = new User_Mapper( $this->settings, $this->policy );
		$this->questions = new Questions( $this->settings );
		$this->engine    = new OAuth_Engine( $this->settings, $this->providers, $this->policy, $this->users );
		$this->reports   = new Reports( $this->questions );
		$this->rest      = new Rest_Api( $this->engine, $this->providers, $this->questions, $this->settings, $this->reports );

		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'rest_api_init', array( $this->rest, 'register_routes' ) );

		( new Password_Auth( $this->settings ) )->hooks();
		( new Login_Throttle( $this->settings ) )->hooks();
		( new Private_Site( $this->settings ) )->hooks();
		( new Shortcodes( $this->settings, $this->providers ) )->hooks();
		$this->questions->hooks();

		$this->init_updater();

		if ( is_admin() ) {
			$admin = new Admin_Settings( $this->settings, $this->providers, $this->questions );
			$admin->hooks();

			$reports_admin = new Admin_Reports( $this->questions, $this->reports );
			$reports_admin->hooks();
		}
	}

	/**
	 * Register self-hosted updates from GitHub releases.
	 *
	 * @return void
	 */
	private function init_updater() {
		$repo = apply_filters( 'authorizenter_github_repo', defined( 'AUTHORIZENTER_GITHUB_REPO' ) ? AUTHORIZENTER_GITHUB_REPO : '', 'core' );
		if ( '' === $repo ) {
			return;
		}
		$updater = new Github_Updater( AUTHORIZENTER_CORE_FILE, 'authorizenter-core', $repo, AUTHORIZENTER_CORE_VERSION, 'authorizenter-core.zip' );
		$updater->hooks();
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'authorizenter', false, dirname( plugin_basename( AUTHORIZENTER_CORE_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: set defaults and flush rewrite rules for REST.
	 *
	 * @return void
	 */
	public static function activate() {
		$settings = new Settings();
		$settings->maybe_set_defaults();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
