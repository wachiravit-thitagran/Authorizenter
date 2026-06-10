<?php
/**
 * Customizable post-login questions.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Stores admin-defined questions and per-user answers.
 *
 * Question definition shape:
 * array(
 *   'id'       => 'is_bia_volunteer',          // unique slug.
 *   'type'     => 'checkbox|radio|select|text|textarea',
 *   'label'    => 'Are you a volunteer from bia.psu.ac.th?',
 *   'required' => true,
 *   'options'  => array( 'Yes', 'No' ),        // for radio/select.
 *   'providers'=> array(),                      // limit to provider ids; empty = all.
 * )
 */
class Questions {

	const ANSWERS_META = 'autorizenter_answers';

	/** Prefix for per-question mirror meta keys (indexed, fast to query). */
	const ANSWER_META_PREFIX = 'autorizenter_answer_';

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
	 * Allowed question types.
	 *
	 * @return string[]
	 */
	public function types() {
		return array( 'checkbox', 'radio', 'select', 'text', 'textarea' );
	}

	/**
	 * All configured questions (sanitized), filterable.
	 *
	 * @return array[]
	 */
	public function all() {
		$raw  = $this->settings->get( 'questions' );
		$list = array();
		foreach ( (array) $raw as $q ) {
			$clean = $this->sanitize_definition( $q );
			if ( $clean ) {
				$list[] = $clean;
			}
		}
		/**
		 * Filter the active question set (e.g. inject dynamic questions).
		 *
		 * @param array[] $list Question definitions.
		 */
		return apply_filters( 'autorizenter_questions', $list );
	}

	/**
	 * Questions that apply to a given provider.
	 *
	 * A question with a non-empty `providers` list only applies to those providers;
	 * a question with an empty list applies to everyone. An empty `$provider`
	 * (unknown) returns all questions.
	 *
	 * @param string $provider Provider id (optional).
	 * @return array[]
	 */
	public function for_provider( $provider = '' ) {
		$out = array();
		foreach ( $this->all() as $q ) {
			if ( ! empty( $q['providers'] ) && '' !== $provider && ! in_array( $provider, (array) $q['providers'], true ) ) {
				continue;
			}
			$out[] = $q;
		}
		return $out;
	}

	/**
	 * Questions applicable to a given provider, minus those already answered.
	 *
	 * @param int    $user_id  User id.
	 * @param string $provider Provider id (optional).
	 * @return array[]
	 */
	public function pending_for_user( $user_id, $provider = '' ) {
		$answers = $this->get_answers( $user_id );
		$pending = array();
		foreach ( $this->for_provider( $provider ) as $q ) {
			if ( ! array_key_exists( $q['id'], $answers ) ) {
				$pending[] = $q;
			}
		}
		return $pending;
	}

	/**
	 * Whether a user still owes any required answers.
	 *
	 * @param int    $user_id  User id.
	 * @param string $provider Provider id.
	 * @return bool
	 */
	public function has_pending_required( $user_id, $provider = '' ) {
		foreach ( $this->pending_for_user( $user_id, $provider ) as $q ) {
			if ( ! empty( $q['required'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get stored answers for a user.
	 *
	 * @param int $user_id User id.
	 * @return array id => value.
	 */
	public function get_answers( $user_id ) {
		$answers = get_user_meta( $user_id, self::ANSWERS_META, true );
		return is_array( $answers ) ? $answers : array();
	}

	/**
	 * Validate and save submitted answers.
	 *
	 * @param int   $user_id User id.
	 * @param array $input   Raw input id => value.
	 * @return true|\WP_Error
	 */
	public function save_answers( $user_id, array $input ) {
		$questions = array();
		foreach ( $this->all() as $q ) {
			$questions[ $q['id'] ] = $q;
		}

		$stored = $this->get_answers( $user_id );

		foreach ( $questions as $id => $q ) {
			$has   = array_key_exists( $id, $input );
			$value = $has ? $input[ $id ] : null;
			$clean = $this->sanitize_answer( $q, $value );

			if ( ! empty( $q['required'] ) && $this->is_empty_answer( $clean ) && ! array_key_exists( $id, $stored ) ) {
				return new \WP_Error(
					'autorizenter_answer_required',
					/* translators: %s: question label */
					sprintf( __( 'Please answer: %s', 'autorizenter' ), $q['label'] ),
					array(
						'status'   => 400,
						'question' => $id,
					)
				);
			}

			if ( $has ) {
				$stored[ $id ] = $clean;
			}
		}

		update_user_meta( $user_id, self::ANSWERS_META, $stored );

		// Mirror each answer to its own meta key so reports can query with an index
		// instead of LIKE-matching the serialized blob.
		foreach ( $stored as $id => $val ) {
			update_user_meta( $user_id, self::ANSWER_META_PREFIX . $id, $this->mirror_value( $val ) );
		}

		/**
		 * Fires after a user's answers are saved.
		 *
		 * @param int   $user_id User id.
		 * @param array $stored  Full answer set.
		 */
		do_action( 'autorizenter_answers_saved', $user_id, $stored );

		if ( ! $this->has_pending_required( $user_id ) ) {
			/**
			 * Fires when a user has completed all required questions.
			 *
			 * @param int $user_id User id.
			 */
			do_action( 'autorizenter_questions_completed', $user_id );
		}

		return true;
	}

	/**
	 * Sanitize a question definition.
	 *
	 * @param mixed $q Raw definition.
	 * @return array|null
	 */
	public function sanitize_definition( $q ) {
		if ( ! is_array( $q ) || empty( $q['id'] ) || empty( $q['label'] ) ) {
			return null;
		}
		$type = isset( $q['type'] ) ? sanitize_key( $q['type'] ) : 'text';
		if ( ! in_array( $type, $this->types(), true ) ) {
			$type = 'text';
		}
		$options = array();
		if ( isset( $q['options'] ) && is_array( $q['options'] ) ) {
			foreach ( $q['options'] as $opt ) {
				$options[] = sanitize_text_field( $opt );
			}
		}
		$providers = array();
		if ( isset( $q['providers'] ) && is_array( $q['providers'] ) ) {
			foreach ( $q['providers'] as $p ) {
				$providers[] = sanitize_key( $p );
			}
		}
		return array(
			'id'        => sanitize_key( $q['id'] ),
			'type'      => $type,
			'label'     => sanitize_text_field( $q['label'] ),
			'required'  => ! empty( $q['required'] ),
			'options'   => $options,
			'providers' => $providers,
		);
	}

	/**
	 * Sanitize an answer according to its question type.
	 *
	 * @param array $q     Question definition.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	private function sanitize_answer( array $q, $value ) {
		switch ( $q['type'] ) {
			case 'checkbox':
				return (bool) $value;
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'radio':
			case 'select':
				$value = sanitize_text_field( (string) $value );
				return in_array( $value, $q['options'], true ) ? $value : '';
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}

	/**
	 * Scalar representation of an answer for the indexed mirror meta key.
	 *
	 * @param mixed $value Stored answer value.
	 * @return string '1'/'0' for booleans, the string value otherwise.
	 */
	private function mirror_value( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		return (string) $value;
	}

	/**
	 * Is an answer considered empty (for required validation)?
	 *
	 * @param mixed $value Clean value.
	 * @return bool
	 */
	private function is_empty_answer( $value ) {
		if ( is_bool( $value ) ) {
			return false === $value;
		}
		return '' === (string) $value;
	}
}
