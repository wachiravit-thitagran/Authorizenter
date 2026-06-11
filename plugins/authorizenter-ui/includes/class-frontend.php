<?php
/**
 * Front-end: shortcodes, assets, question gating.
 *
 * @package Authorizenter\UI
 */

namespace Authorizenter\UI;

defined( 'ABSPATH' ) || exit;

/**
 * Registers shortcodes and the post-login question redirect, all built on the
 * Core REST API / hooks. Contains no OAuth logic of its own.
 */
class Frontend {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_shortcode( 'authorizenter_login', array( $this, 'render_login' ) );
		// UI owns the visual [authorizenter_button] (Core owns only the bare
		// [authorizenter_url]); label/icon rendering live here at the template level.
		add_shortcode( 'authorizenter_button', array( $this, 'render_button' ) );
		add_shortcode( 'authorizenter_logout', array( $this, 'render_logout' ) );
		add_shortcode( 'authorizenter_questions', array( $this, 'render_questions' ) );
		add_shortcode( 'authorizenter_answers', array( $this, 'render_answers' ) );
		add_shortcode( 'authorizenter_stats', array( $this, 'render_stats' ) );
		add_shortcode( 'authorizenter_pending_form', array( $this, 'render_pending_form' ) );

		// Deprecated aliases for the previous "autorizenter" spelling: still work so
		// existing pages don't break, but emit a deprecation notice under WP_DEBUG.
		foreach ( array_keys( $this->legacy_shortcodes() ) as $legacy_tag ) {
			add_shortcode( $legacy_tag, array( $this, 'legacy_shortcode' ) );
		}

		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		// Tell Core where the questions page lives, so it can redirect there.
		add_filter( 'authorizenter_questions_url', array( $this, 'questions_url' ) );
		add_filter( 'authorizenter_login_url', array( $this, 'login_url' ) );

		// Map a context to its login page (used by Core for deny fallbacks).
		add_filter( 'authorizenter_context_login_url', array( $this, 'context_login_url' ), 10, 2 );

		// Route pending (awaiting-approval) users: to the pre-approval questions
		// form when questions apply to their provider, else the configured pending
		// page (or the default form page).
		add_filter( 'authorizenter_pending_redirect', array( $this, 'pending_redirect_url' ), 10, 3 );

		// Expose the login page id so Core's private-site mode can allow it.
		add_filter( 'authorizenter_login_page_id', array( $this, 'login_page_id' ) );

		// Keep a login page in sync with each configured context.
		add_action( 'admin_init', array( Page_Installer::class, 'ensure_context_pages' ) );

		// Enforce the question gate on every front-end page load.
		add_action( 'template_redirect', array( $this, 'enforce_question_gate' ) );
	}

	/**
	 * Map of deprecated (old-spelling) shortcode tag => current render method.
	 *
	 * @return array<string,string>
	 */
	private function legacy_shortcodes() {
		return array(
			'autorizenter_login'        => 'render_login',
			'autorizenter_button'       => 'render_button',
			'autorizenter_logout'       => 'render_logout',
			'autorizenter_questions'    => 'render_questions',
			'autorizenter_answers'      => 'render_answers',
			'autorizenter_stats'        => 'render_stats',
			'autorizenter_pending_form' => 'render_pending_form',
		);
	}

	/**
	 * Render a deprecated alias shortcode: emit a deprecation notice (WP_DEBUG)
	 * then delegate to the current handler so existing pages keep working.
	 *
	 * @param array|string $atts    Shortcode attributes.
	 * @param string|null  $content Enclosed content (unused).
	 * @param string       $tag     The shortcode tag used.
	 * @return string
	 */
	public function legacy_shortcode( $atts, $content, $tag ) {
		$map = $this->legacy_shortcodes();
		if ( ! isset( $map[ $tag ] ) ) {
			return '';
		}
		$new = str_replace( 'autorizenter_', 'authorizenter_', $tag );
		_deprecated_function( esc_html( "[{$tag}] shortcode" ), 'Authorizenter 0.2.0', esc_html( "[{$new}]" ) );
		return $this->{ $map[ $tag ] }( (array) $atts );
	}

	/**
	 * Enqueue assets.
	 *
	 * @return void
	 */
	public function assets() {
		wp_register_style( 'authorizenter-ui', AUTHORIZENTER_UI_URL . 'assets/authorizenter.css', array(), AUTHORIZENTER_UI_VERSION );
		wp_register_script( 'authorizenter-ui', AUTHORIZENTER_UI_URL . 'assets/authorizenter.js', array(), AUTHORIZENTER_UI_VERSION, true );
		wp_localize_script(
			'authorizenter-ui',
			'AuthorizenterUI',
			array(
				'restUrl' => esc_url_raw( rest_url( 'autorizenter/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * The configured questions page URL (page holding [authorizenter_questions]).
	 *
	 * @return string
	 */
	public function questions_url() {
		$id = (int) get_option( Page_Installer::OPT_QUESTIONS_PAGE, 0 );
		return $id ? get_permalink( $id ) : '';
	}

	/**
	 * The configured login page URL.
	 *
	 * @return string
	 */
	public function login_url() {
		$id = (int) get_option( Page_Installer::OPT_LOGIN_PAGE, 0 );
		return $id ? get_permalink( $id ) : wp_login_url();
	}

	/**
	 * Resolve a context's login page URL for Core's deny fallback.
	 *
	 * @param string $default    Default URL.
	 * @param string $context_id Context id.
	 * @return string
	 */
	public function context_login_url( $default, $context_id ) {
		$url = Page_Installer::url_for_context( $context_id );
		return '' !== $url ? $url : $default;
	}

	/**
	 * Decide where a pending (awaiting-approval) user goes.
	 *
	 * If any questions apply to the login provider, route to the pre-approval
	 * questions form (the page holding [authorizenter_pending_form]) so they complete
	 * them first. Otherwise use the admin's configured pending page, or fall back to
	 * the default form page (which shows a generic "awaiting approval" message).
	 *
	 * @param string $configured Configured pending_redirect (may be empty).
	 * @param string $provider   Provider the user signed in with.
	 * @param array  $context    Resolved context.
	 * @return string
	 */
	public function pending_redirect_url( $configured, $provider, $context ) {
		if ( ! function_exists( 'Authorizenter\\Core\\authorizenter_core' ) ) {
			return $configured;
		}
		$core = \Authorizenter\Core\authorizenter_core();

		$page     = (int) apply_filters( 'authorizenter_pending_page_id', (int) get_option( Page_Installer::OPT_PENDING_PAGE, 0 ) );
		$form_url = ( $page && 'publish' === get_post_status( $page ) ) ? (string) get_permalink( $page ) : '';

		// Questions apply to this provider → send them to fill the form first.
		if ( '' !== $form_url && ! empty( $core->questions->for_provider( (string) $provider ) ) ) {
			return $form_url;
		}

		// No questions: honour the configured pending page, else fall back to the
		// default form page (its empty-questions state is a fine waiting message).
		if ( '' !== (string) $configured ) {
			return (string) $configured;
		}
		return '' !== $form_url ? $form_url : (string) $configured;
	}

	/**
	 * The default login page id (for private-site allowlisting).
	 *
	 * @param int $id Incoming id.
	 * @return int
	 */
	public function login_page_id( $id ) {
		$page = (int) get_option( Page_Installer::OPT_LOGIN_PAGE, 0 );
		return $page ? $page : (int) $id;
	}

	/**
	 * Redirect logged-in users with pending required questions to the form.
	 *
	 * @return void
	 */
	public function enforce_question_gate() {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}
		if ( ! function_exists( 'Authorizenter\\Core\\authorizenter_core' ) ) {
			return;
		}

		$questions_id = (int) get_option( Page_Installer::OPT_QUESTIONS_PAGE, 0 );
		if ( $questions_id && is_page( $questions_id ) ) {
			return; // Already on the questions page.
		}

		$core     = \Authorizenter\Core\authorizenter_core();
		$user_id  = get_current_user_id();
		$provider = (string) get_user_meta( $user_id, 'authorizenter_last_provider', true );
		if ( ! $core->questions->has_pending_required( $user_id, $provider ) ) {
			return;
		}

		$url = $this->questions_url();
		if ( '' !== $url ) {
			wp_safe_redirect( add_query_arg( 'return_to', rawurlencode( $this->current_url() ), $url ) );
			exit;
		}
	}

	/**
	 * Render login buttons shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_login( $atts ) {
		$atts = shortcode_atts(
			array(
				'return_to' => '',
				'context'   => 'default',
			),
			$atts,
			'authorizenter_login'
		);

		if ( is_user_logged_in() ) {
			return '<div class="authorizenter-login authorizenter-login--in">' .
				esc_html__( 'You are already signed in.', 'authorizenter' ) . '</div>';
		}

		wp_enqueue_style( 'authorizenter-ui' );

		$core       = \Authorizenter\Core\authorizenter_core();
		$context_id = sanitize_key( $atts['context'] );
		$context    = $core->settings->get_context( $context_id );
		$providers  = $core->providers->enabled_for_context( $context );
		$return_to  = '' !== $atts['return_to'] ? $atts['return_to'] : $this->current_url();

		$error = isset( $_GET['authorizenter_error'] ) ? sanitize_text_field( wp_unslash( $_GET['authorizenter_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		include AUTHORIZENTER_UI_DIR . 'templates/login.php';
		return ob_get_clean();
	}

	/**
	 * Single provider login button: [authorizenter_button provider="google" context="default"].
	 *
	 * Template-level UI for one provider: brand logo (or a custom one) plus the
	 * label. The bare authorize URL itself is Core's concern (see the
	 * [authorizenter_url] shortcode); this method resolves the provider through the
	 * Core registry and wraps it in styled markup. Returns an empty string when the
	 * provider is missing/disabled in the context, or the visitor is logged in.
	 *
	 * @param array $atts Shortcode attributes (provider, context, return_to).
	 * @return string
	 */
	public function render_button( $atts ) {
		$atts = shortcode_atts(
			array(
				'provider'  => '',
				'context'   => 'default',
				'return_to' => '',
			),
			$atts,
			'authorizenter_button'
		);

		$preview = function_exists( 'Authorizenter\\Core\\authorizenter_is_builder_preview' ) && \Authorizenter\Core\authorizenter_is_builder_preview();
		if ( ( is_user_logged_in() && ! $preview ) || '' === $atts['provider'] ) {
			return '';
		}
		if ( ! function_exists( 'Authorizenter\\Core\\authorizenter_core' ) ) {
			return '';
		}

		$core        = \Authorizenter\Core\authorizenter_core();
		$context_id  = sanitize_key( $atts['context'] );
		$provider_id = sanitize_key( $atts['provider'] );
		$context     = $core->settings->get_context( $context_id );
		$providers   = $core->providers->enabled_for_context( $context );

		if ( ! isset( $providers[ $provider_id ] ) ) {
			return '';
		}

		$provider  = $providers[ $provider_id ];
		$return_to = '' !== $atts['return_to'] ? $atts['return_to'] : $this->current_url();
		$url       = add_query_arg(
			array(
				'context'   => $context_id,
				'return_to' => rawurlencode( $return_to ),
			),
			rest_url( 'autorizenter/v1/authorize/' . $provider_id )
		);

		wp_enqueue_style( 'authorizenter-ui' );

		$logo = $provider->logo_url();
		$icon = '' !== $logo
			? '<img src="' . esc_url( $logo ) . '" alt="" width="20" height="20" loading="lazy" />'
			: \Authorizenter\UI\Logos::svg( $provider_id );

		return '<a class="authorizenter-btn authorizenter-btn--' . esc_attr( $provider_id ) . '" href="' . esc_url( $url ) . '">' .
			'<span class="authorizenter-btn__icon">' . $icon . '</span>' .
			'<span class="authorizenter-btn__label">' .
				/* translators: %s: provider label */
				sprintf( esc_html__( 'Continue with %s', 'authorizenter' ), esc_html( $provider->label() ) ) .
			'</span>' .
			'</a>';
	}

	/**
	 * Render a logout link/button.
	 *
	 * @param array $atts Shortcode attributes (return_to, label).
	 * @return string
	 */
	public function render_logout( $atts ) {
		$atts = shortcode_atts(
			array(
				'return_to' => '',
				'label'     => __( 'Sign out', 'authorizenter' ),
			),
			$atts,
			'authorizenter_logout'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		wp_enqueue_style( 'authorizenter-ui' );

		$return_to = '' !== $atts['return_to'] ? $atts['return_to'] : home_url( '/' );
		$url       = add_query_arg(
			'return_to',
			rawurlencode( $return_to ),
			rest_url( 'autorizenter/v1/logout' )
		);

		return '<a class="authorizenter-btn authorizenter-btn--logout" href="' . esc_url( $url ) . '">' .
			esc_html( $atts['label'] ) . '</a>';
	}

	/**
	 * Render the questions form shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_questions( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="authorizenter-questions">' . esc_html__( 'Please sign in first.', 'authorizenter' ) . '</div>';
		}

		$atts = shortcode_atts(
			array(
				// Where to send the user after completing (overrides ?return_to=).
				'redirect' => '',
				// Custom message shown on success (empty = default + redirect).
				'message'  => '',
			),
			$atts,
			'authorizenter_questions'
		);

		wp_enqueue_style( 'authorizenter-ui' );
		wp_enqueue_script( 'authorizenter-ui' );

		$core      = \Authorizenter\Core\authorizenter_core();
		$user_id   = get_current_user_id();
		$provider  = (string) get_user_meta( $user_id, 'authorizenter_last_provider', true );
		$questions = $core->questions->pending_for_user( $user_id, $provider );
		$return_to = isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( $_GET['return_to'] ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// A shortcode redirect attribute wins over the ?return_to= query arg.
		if ( '' !== $atts['redirect'] ) {
			$return_to = esc_url_raw( $atts['redirect'] );
		}
		$done_message = (string) $atts['message'];

		ob_start();
		include AUTHORIZENTER_UI_DIR . 'templates/questions.php';
		return ob_get_clean();
	}

	/**
	 * Display the current user's submitted answers.
	 *
	 * Usage:
	 *   [authorizenter_answers]                              All answers as a list.
	 *   [authorizenter_answers question="is_bia_volunteer"]  A single answer's value.
	 *   [authorizenter_answers show_label="no"]              Values only, no labels.
	 *
	 * Only ever shows the logged-in user's own answers.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_answers( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="authorizenter-answers">' . esc_html__( 'Please sign in first.', 'authorizenter' ) . '</div>';
		}

		$atts = shortcode_atts(
			array(
				'question'   => '',
				'show_label' => 'yes',
				'empty'      => __( 'No answer provided.', 'authorizenter' ),
			),
			$atts,
			'authorizenter_answers'
		);

		$core    = \Authorizenter\Core\authorizenter_core();
		$answers = $core->questions->get_answers( get_current_user_id() );

		// Map question id => definition for labels and types.
		$defs = array();
		foreach ( $core->questions->all() as $question ) {
			$defs[ $question['id'] ] = $question;
		}

		// A single question's value.
		$only = sanitize_key( $atts['question'] );
		if ( '' !== $only ) {
			if ( ! array_key_exists( $only, $answers ) ) {
				return '<span class="authorizenter-answer authorizenter-answer--empty">' . esc_html( $atts['empty'] ) . '</span>';
			}
			$type = isset( $defs[ $only ]['type'] ) ? $defs[ $only ]['type'] : 'text';
			return '<span class="authorizenter-answer">' . esc_html( $this->format_answer_value( $answers[ $only ], $type ) ) . '</span>';
		}

		// All answers as a definition list.
		if ( empty( $answers ) ) {
			return '<div class="authorizenter-answers authorizenter-answers--empty">' . esc_html( $atts['empty'] ) . '</div>';
		}

		$show_label = 'no' !== strtolower( (string) $atts['show_label'] );
		$out        = '<dl class="authorizenter-answers">';
		foreach ( $answers as $id => $value ) {
			$type  = isset( $defs[ $id ]['type'] ) ? $defs[ $id ]['type'] : 'text';
			$label = isset( $defs[ $id ]['label'] ) ? $defs[ $id ]['label'] : $id;
			if ( $show_label ) {
				$out .= '<dt class="authorizenter-answers__label">' . esc_html( $label ) . '</dt>';
			}
			$out .= '<dd class="authorizenter-answers__value">' . esc_html( $this->format_answer_value( $value, $type ) ) . '</dd>';
		}
		$out .= '</dl>';
		return $out;
	}

	/**
	 * Human-readable answer value (booleans become Yes/No).
	 *
	 * @param mixed  $value Stored answer value.
	 * @param string $type  Question type.
	 * @return string
	 */
	private function format_answer_value( $value, $type ) {
		if ( is_bool( $value ) || 'checkbox' === $type ) {
			$truthy = is_bool( $value ) ? $value : ( '1' === (string) $value );
			return $truthy ? __( 'Yes', 'authorizenter' ) : __( 'No', 'authorizenter' );
		}
		return (string) $value;
	}

	/**
	 * Aggregate count for a question across all users — returns a plain number.
	 *
	 * Returns just an integer (as a string) so it can be placed anywhere: a stat
	 * card, inline text, or fed into your own chart. Aggregate totals only; never
	 * exposes individual identities.
	 *
	 * Usage:
	 *   [authorizenter_stats question="is_bia_volunteer"]            ticked count
	 *   [authorizenter_stats question="is_bia_volunteer" value="0"]  un-ticked count
	 *   [authorizenter_stats question="faculty" value="Science"]     count for one option
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string Integer as a string (e.g. "37"); "" when not permitted/unknown.
	 */
	public function render_stats( $atts ) {
		$atts = shortcode_atts(
			array(
				'question' => '',
				'value'    => '',     // specific answer value to count; empty = total / ticked.
				'cap'      => 'read',  // capability required; '' = public.
			),
			$atts,
			'authorizenter_stats'
		);

		if ( '' !== $atts['cap'] && ! current_user_can( sanitize_key( $atts['cap'] ) ) ) {
			return '';
		}

		$core = \Authorizenter\Core\authorizenter_core();
		$only = sanitize_key( $atts['question'] );
		$def  = null;
		foreach ( $core->questions->all() as $question ) {
			if ( $question['id'] === $only ) {
				$def = $question;
				break;
			}
		}
		if ( null === $def ) {
			return '';
		}

		$report = $core->reports->question_report( $def );
		$value  = (string) $atts['value'];

		if ( '' !== $value ) {
			// Count for a specific answer value (e.g. an option, or "0"/"1" for a checkbox).
			$count = isset( $report['breakdown'][ $value ]['count'] ) ? (int) $report['breakdown'][ $value ]['count'] : 0;
		} else {
			// No value: checkbox counts ticks; other types count all who answered.
			$count = (int) $report['answered'];
		}

		return (string) $count;
	}

	/**
	 * Render the pre-approval form shortcode.
	 *
	 * Shown on the pending_redirect page. Reads the one-time token from the URL,
	 * displays all configured questions, and submits answers to POST /pending/answers.
	 *
	 * @param array $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function render_pending_form( $atts ) {
		$atts = shortcode_atts(
			array(
				// Custom message shown after submitting (empty = default).
				'message'  => '',
				// Optional URL to send the user to after submitting.
				'redirect' => '',
			),
			$atts,
			'authorizenter_pending_form'
		);

		$token = isset( $_GET['azr_pending_token'] ) ? sanitize_text_field( wp_unslash( $_GET['azr_pending_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return '<div class="authorizenter-pending-form">' .
				esc_html__( 'Invalid or missing access token.', 'authorizenter' ) .
				'</div>';
		}

		$done_message = (string) $atts['message'];
		$redirect     = '' !== $atts['redirect'] ? esc_url_raw( $atts['redirect'] ) : '';

		$core      = \Authorizenter\Core\authorizenter_core();
		$provider  = ( new \Authorizenter\Core\Access_List( $core->settings ) )->pending_provider( $token );
		$questions = $core->questions->for_provider( $provider );

		if ( empty( $questions ) ) {
			return '<div class="authorizenter-pending-form">' .
				esc_html__( 'Your request is pending approval. An administrator will review it shortly.', 'authorizenter' ) .
				'</div>';
		}

		wp_enqueue_style( 'authorizenter-ui' );
		wp_enqueue_script( 'authorizenter-ui' );

		ob_start();
		include AUTHORIZENTER_UI_DIR . 'templates/pending-form.php';
		return ob_get_clean();
	}

	/**
	 * Current front-end URL (host-safe).
	 *
	 * @return string
	 */
	private function current_url() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return home_url( esc_url_raw( $path ) );
	}
}
