<?php
/**
 * Aggregates question answers for reporting.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Builds counts and respondent lists from the indexed answer meta keys written
 * by Questions (autorizenter_answer_{id}). Querying these is index-friendly,
 * unlike LIKE-matching the serialized autorizenter_answers blob.
 */
class Reports {

	/**
	 * Questions manager.
	 *
	 * @var Questions
	 */
	private $questions;

	/**
	 * Constructor.
	 *
	 * @param Questions $questions Questions manager.
	 */
	public function __construct( Questions $questions ) {
		$this->questions = $questions;
	}

	/**
	 * Per-question report for every configured question.
	 *
	 * @return array[]
	 */
	public function summary() {
		$out = array();
		foreach ( $this->questions->all() as $def ) {
			$out[] = $this->question_report( $def );
		}
		return $out;
	}

	/**
	 * Report for a single question definition.
	 *
	 * @param array $def Question definition.
	 * @return array {
	 *     @type string $id
	 *     @type string $label
	 *     @type string $type
	 *     @type bool   $required
	 *     @type int    $answered     Total users who answered.
	 *     @type array  $breakdown    value => array( count, user_ids[] ).
	 * }
	 */
	public function question_report( array $def ) {
		global $wpdb;

		$key  = Questions::ANSWER_META_PREFIX . $def['id'];
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $key )
		);

		$breakdown = array();
		$answered  = 0;
		foreach ( (array) $rows as $row ) {
			$value = (string) $row->meta_value;
			if ( ! isset( $breakdown[ $value ] ) ) {
				$breakdown[ $value ] = array(
					'count'    => 0,
					'user_ids' => array(),
				);
			}
			++$breakdown[ $value ]['count'];
			$breakdown[ $value ]['user_ids'][] = (int) $row->user_id;

			// "answered" = a non-empty / truthy response.
			if ( 'checkbox' === $def['type'] ) {
				if ( '1' === $value ) {
					++$answered;
				}
			} elseif ( '' !== $value ) {
				++$answered;
			}
		}

		return array(
			'id'        => $def['id'],
			'label'     => $def['label'],
			'type'      => $def['type'],
			'required'  => ! empty( $def['required'] ),
			'answered'  => $answered,
			'breakdown' => $breakdown,
		);
	}

	/**
	 * Respondents to a question, optionally filtered to a specific value.
	 *
	 * @param string      $question_id Question id.
	 * @param string|null $value       Only users whose answer equals this value.
	 * @return array[] List of array( id, name, email, value ).
	 */
	public function respondents( $question_id, $value = null ) {
		global $wpdb;

		$key  = Questions::ANSWER_META_PREFIX . sanitize_key( $question_id );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", $key )
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$val = (string) $row->meta_value;
			if ( null !== $value && $val !== (string) $value ) {
				continue;
			}
			$user = get_userdata( (int) $row->user_id );
			if ( ! $user ) {
				continue;
			}
			$out[] = array(
				'id'    => (int) $user->ID,
				'name'  => $user->display_name,
				'email' => $user->user_email,
				'value' => $val,
			);
		}
		return $out;
	}

	/**
	 * Full answer matrix for CSV export: every user who answered anything, with a
	 * column per question.
	 *
	 * @return array {
	 *     @type array $questions Question definitions (ordered columns).
	 *     @type array $rows      array( id, name, email, answers[id => value] ).
	 * }
	 */
	public function matrix() {
		$defs  = $this->questions->all();
		$users = get_users( array( 'meta_key' => Questions::ANSWERS_META ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key

		$rows = array();
		foreach ( $users as $user ) {
			$answers = $this->questions->get_answers( $user->ID );
			$rows[]  = array(
				'id'      => (int) $user->ID,
				'name'    => $user->display_name,
				'email'   => $user->user_email,
				'answers' => $answers,
			);
		}

		return array(
			'questions' => $defs,
			'rows'      => $rows,
		);
	}
}
