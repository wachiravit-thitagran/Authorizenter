<?php
/**
 * Front-end: shortcodes, assets, question gating.
 *
 * @package Autorizenter\UI
 */

namespace Autorizenter\UI;

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
		add_shortcode( 'autorizenter_login', array( $this, 'render_login' ) );
		// UI owns the visual [autorizenter_button] (Core owns only the bare
		// [autorizenter_url]); label/icon rendering live here at the template level.
		add_shortcode( 'autorizenter_button', array( $this, 'render_button' ) );
		add_shortcode( 'autorizenter_logout', array( $this, 'render_logout' ) );
		add_shortcode( 'autorizenter_questions', array( $this, 'render_questions' ) );
		add_shortcode( 'autorizenter_answers', array( $this, 'render_answers' ) );
		add_shortcode( 'autorizenter_stats', array( $this, 'render_stats' ) );
		add_shortcode( 'autorizenter_pending_form', array( $this, 'render_pending_form' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );

		// Tell Core where the questions page lives, so it can redirect there.
		add_filter( 'autorizenter_questions_url', array( $this, 'questions_url' ) );
		add_filter( 'autorizenter_login_url', array( $this, 'login_url' ) );

		// Map a context to its login page (used by Core for deny fallbacks).
		add_filter( 'autorizenter_context_login_url', array( $this, 'context_login_url' ), 10, 2 );

		// Point pending (awaiting-approval) users at the pre-approval form page,
		// unless a context already configures its own pending_redirect.
		add_filter( 'autorizenter_context', array( $this, 'context_pending_redirect' ), 10, 2 );

		// Expose the login page id so Core's private-site mode can allow it.
		add_filter( 'autorizenter_login_page_id', array( $this, 'login_page_id' ) );

		// Keep a login page in sync with each configured context.
		add_action( 'admin_init', array( Page_Installer::class, 'ensure_context_pages' ) );

		// Enforce the question gate on every front-end page load.
		add_action( 'template_redirect', array( $this, 'enforce_question_gate' ) );
	}

	/**
	 * Enqueue assets.
	 *
	 * @return void
	 */
	public function assets() {
		wp_register_style( 'autorizenter-ui', AUTORIZENTER_UI_URL . 'assets/autorizenter.css', array(), AUTORIZENTER_UI_VERSION );
		wp_register_script( 'autorizenter-ui', AUTORIZENTER_UI_URL . 'assets/autorizenter.js', array(), AUTORIZENTER_UI_VERSION, true );
		wp_localize_script(
			'autorizenter-ui',
			'AutorizenterUI',
			array(
				'restUrl' => esc_url_raw( rest_url( 'autorizenter/v1' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * The configured questions page URL (page holding [autorizenter_questions]).
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
	 * Send awaiting-approval (pending) users to the pre-approval form page.
	 *
	 * Only fills in pending_redirect when the context hasn't set its own, so admins
	 * keep full control. The page holds [autorizenter_pending_form], which renders
	 * the same questions configured under Questions for the user to complete while
	 * waiting for approval.
	 *
	 * @param array  $context Resolved context.
	 * @param string $id      Context id.
	 * @return array
	 */
	public function context_pending_redirect( $context, $id ) {
		if ( ! is_array( $context ) || ! empty( $context['pending_redirect'] ) ) {
			return $context;
		}
		$page = (int) get_option( Page_Installer::OPT_PENDING_PAGE, 0 );
		if ( $page ) {
			$url = get_permalink( $page );
			if ( $url ) {
				$context['pending_redirect'] = (string) $url;
			}
		}
		return $context;
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
		if ( ! function_exists( 'Autorizenter\\Core\\autorizenter_core' ) ) {
			return;
		}

		$questions_id = (int) get_option( Page_Installer::OPT_QUESTIONS_PAGE, 0 );
		if ( $questions_id && is_page( $questions_id ) ) {
			return; // Already on the questions page.
		}

		$core = \Autorizenter\Core\autorizenter_core();
		if ( ! $core->questions->has_pending_required( get_current_user_id() ) ) {
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
			'autorizenter_login'
		);

		if ( is_user_logged_in() ) {
			return '<div class="autorizenter-login autorizenter-login--in">' .
				esc_html__( 'You are already signed in.', 'autorizenter' ) . '</div>';
		}

		wp_enqueue_style( 'autorizenter-ui' );

		$core       = \Autorizenter\Core\autorizenter_core();
		$context_id = sanitize_key( $atts['context'] );
		$context    = $core->settings->get_context( $context_id );
		$providers  = $core->providers->enabled_for_context( $context );
		$return_to  = '' !== $atts['return_to'] ? $atts['return_to'] : $this->current_url();

		$error = isset( $_GET['autorizenter_error'] ) ? sanitize_text_field( wp_unslash( $_GET['autorizenter_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		ob_start();
		include AUTORIZENTER_UI_DIR . 'templates/login.php';
		return ob_get_clean();
	}

	/**
	 * Single provider login button: [autorizenter_button provider="google" context="default"].
	 *
	 * Template-level UI for one provider: brand logo (or a custom one) plus the
	 * label. The bare authorize URL itself is Core's concern (see the
	 * [autorizenter_url] shortcode); this method resolves the provider through the
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
			'autorizenter_button'
		);

		if ( is_user_logged_in() || '' === $atts['provider'] ) {
			return '';
		}
		if ( ! function_exists( 'Autorizenter\\Core\\autorizenter_core' ) ) {
			return '';
		}

		$core        = \Autorizenter\Core\autorizenter_core();
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

		wp_enqueue_style( 'autorizenter-ui' );

		$logo = $provider->logo_url();
		$icon = '' !== $logo
			? '<img src="' . esc_url( $logo ) . '" alt="" width="20" height="20" loading="lazy" />'
			: \Autorizenter\UI\Logos::svg( $provider_id );

		return '<a class="autorizenter-btn autorizenter-btn--' . esc_attr( $provider_id ) . '" href="' . esc_url( $url ) . '">' .
			'<span class="autorizenter-btn__icon">' . $icon . '</span>' .
			'<span class="autorizenter-btn__label">' .
				/* translators: %s: provider label */
				sprintf( esc_html__( 'Continue with %s', 'autorizenter' ), esc_html( $provider->label() ) ) .
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
				'label'     => __( 'Sign out', 'autorizenter' ),
			),
			$atts,
			'autorizenter_logout'
		);

		if ( ! is_user_logged_in() ) {
			return '';
		}

		wp_enqueue_style( 'autorizenter-ui' );

		$return_to = '' !== $atts['return_to'] ? $atts['return_to'] : home_url( '/' );
		$url       = add_query_arg(
			'return_to',
			rawurlencode( $return_to ),
			rest_url( 'autorizenter/v1/logout' )
		);

		return '<a class="autorizenter-btn autorizenter-btn--logout" href="' . esc_url( $url ) . '">' .
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
			return '<div class="autorizenter-questions">' . esc_html__( 'Please sign in first.', 'autorizenter' ) . '</div>';
		}

		$atts = shortcode_atts(
			array(
				// Where to send the user after completing (overrides ?return_to=).
				'redirect' => '',
				// Custom message shown on success (empty = default + redirect).
				'message'  => '',
			),
			$atts,
			'autorizenter_questions'
		);

		wp_enqueue_style( 'autorizenter-ui' );
		wp_enqueue_script( 'autorizenter-ui' );

		$core      = \Autorizenter\Core\autorizenter_core();
		$questions = $core->questions->pending_for_user( get_current_user_id() );
		$return_to = isset( $_GET['return_to'] ) ? esc_url_raw( wp_unslash( $_GET['return_to'] ) ) : home_url( '/' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// A shortcode redirect attribute wins over the ?return_to= query arg.
		if ( '' !== $atts['redirect'] ) {
			$return_to = esc_url_raw( $atts['redirect'] );
		}
		$done_message = (string) $atts['message'];

		ob_start();
		include AUTORIZENTER_UI_DIR . 'templates/questions.php';
		return ob_get_clean();
	}

	/**
	 * Display the current user's submitted answers.
	 *
	 * Usage:
	 *   [autorizenter_answers]                              All answers as a list.
	 *   [autorizenter_answers question="is_bia_volunteer"]  A single answer's value.
	 *   [autorizenter_answers show_label="no"]              Values only, no labels.
	 *
	 * Only ever shows the logged-in user's own answers.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_answers( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<div class="autorizenter-answers">' . esc_html__( 'Please sign in first.', 'autorizenter' ) . '</div>';
		}

		$atts = shortcode_atts(
			array(
				'question'   => '',
				'show_label' => 'yes',
				'empty'      => __( 'No answer provided.', 'autorizenter' ),
			),
			$atts,
			'autorizenter_answers'
		);

		$core    = \Autorizenter\Core\autorizenter_core();
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
				return '<span class="autorizenter-answer autorizenter-answer--empty">' . esc_html( $atts['empty'] ) . '</span>';
			}
			$type = isset( $defs[ $only ]['type'] ) ? $defs[ $only ]['type'] : 'text';
			return '<span class="autorizenter-answer">' . esc_html( $this->format_answer_value( $answers[ $only ], $type ) ) . '</span>';
		}

		// All answers as a definition list.
		if ( empty( $answers ) ) {
			return '<div class="autorizenter-answers autorizenter-answers--empty">' . esc_html( $atts['empty'] ) . '</div>';
		}

		$show_label = 'no' !== strtolower( (string) $atts['show_label'] );
		$out        = '<dl class="autorizenter-answers">';
		foreach ( $answers as $id => $value ) {
			$type  = isset( $defs[ $id ]['type'] ) ? $defs[ $id ]['type'] : 'text';
			$label = isset( $defs[ $id ]['label'] ) ? $defs[ $id ]['label'] : $id;
			if ( $show_label ) {
				$out .= '<dt class="autorizenter-answers__label">' . esc_html( $label ) . '</dt>';
			}
			$out .= '<dd class="autorizenter-answers__value">' . esc_html( $this->format_answer_value( $value, $type ) ) . '</dd>';
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
			return $truthy ? __( 'Yes', 'autorizenter' ) : __( 'No', 'autorizenter' );
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
	 *   [autorizenter_stats question="is_bia_volunteer"]            ticked count
	 *   [autorizenter_stats question="is_bia_volunteer" value="0"]  un-ticked count
	 *   [autorizenter_stats question="faculty" value="Science"]     count for one option
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
			'autorizenter_stats'
		);

		if ( '' !== $atts['cap'] && ! current_user_can( sanitize_key( $atts['cap'] ) ) ) {
			return '';
		}

		$core = \Autorizenter\Core\autorizenter_core();
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
			'autorizenter_pending_form'
		);

		$token = isset( $_GET['azr_pending_token'] ) ? sanitize_text_field( wp_unslash( $_GET['azr_pending_token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $token ) {
			return '<div class="autorizenter-pending-form">' .
				esc_html__( 'Invalid or missing access token.', 'autorizenter' ) .
				'</div>';
		}

		$done_message = (string) $atts['message'];
		$redirect     = '' !== $atts['redirect'] ? esc_url_raw( $atts['redirect'] ) : '';

		$core      = \Autorizenter\Core\autorizenter_core();
		$questions = $core->questions->all();

		if ( empty( $questions ) ) {
			return '<div class="autorizenter-pending-form">' .
				esc_html__( 'Your request is pending approval. An administrator will review it shortly.', 'autorizenter' ) .
				'</div>';
		}

		wp_enqueue_style( 'autorizenter-ui' );
		wp_enqueue_script( 'autorizenter-ui' );

		ob_start();
		include AUTORIZENTER_UI_DIR . 'templates/pending-form.php';
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
