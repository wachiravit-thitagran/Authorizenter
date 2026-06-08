<?php
/**
 * Admin: question answer reports + CSV export.
 *
 * @package Autorizenter\Core
 */

namespace Autorizenter\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Settings → Autorizenter Report with per-question counts, respondent lists,
 * and a CSV export of the full answer matrix.
 */
class Admin_Reports {

	const CAP = 'list_users';

	/**
	 * Questions manager.
	 *
	 * @var Questions
	 */
	private $questions;

	/**
	 * Reports aggregator.
	 *
	 * @var Reports
	 */
	private $reports;

	/**
	 * Constructor.
	 *
	 * @param Questions $questions Questions manager.
	 * @param Reports   $reports   Reports aggregator.
	 */
	public function __construct( Questions $questions, Reports $reports ) {
		$this->questions = $questions;
		$this->reports   = $reports;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_autorizenter_export_answers', array( $this, 'export_csv' ) );
	}

	/**
	 * Add the report submenu.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'options-general.php',
			__( 'Autorizenter Report', 'autorizenter' ),
			__( 'Autorizenter Report', 'autorizenter' ),
			self::CAP,
			'autorizenter-report',
			array( $this, 'render' )
		);
	}

	/**
	 * Render the report page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		$detail_q = isset( $_GET['question'] ) ? sanitize_key( wp_unslash( $_GET['question'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$detail_v = isset( $_GET['value'] ) ? sanitize_text_field( wp_unslash( $_GET['value'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=autorizenter_export_answers' ),
			'autorizenter_export_answers'
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Autorizenter Report', 'autorizenter' ); ?></h1>
			<p><a class="button button-primary" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Export all answers (CSV)', 'autorizenter' ); ?></a></p>

			<?php if ( '' !== $detail_q ) : ?>
				<?php $this->render_detail( $detail_q, $detail_v ); ?>
			<?php else : ?>
				<?php $this->render_summary(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the per-question summary table.
	 *
	 * @return void
	 */
	private function render_summary() {
		$summary = $this->reports->summary();
		if ( empty( $summary ) ) {
			echo '<p>' . esc_html__( 'No questions are configured yet.', 'autorizenter' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Question', 'autorizenter' ); ?></th>
					<th><?php esc_html_e( 'Type', 'autorizenter' ); ?></th>
					<th><?php esc_html_e( 'Answered', 'autorizenter' ); ?></th>
					<th><?php esc_html_e( 'Breakdown', 'autorizenter' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $summary as $q ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $q['label'] ); ?></strong><br />
						<code><?php echo esc_html( $q['id'] ); ?></code>
					</td>
					<td><?php echo esc_html( $q['type'] ); ?></td>
					<td>
						<a href="<?php echo esc_url( $this->detail_link( $q['id'] ) ); ?>"><?php echo (int) $q['answered']; ?></a>
					</td>
					<td><?php echo wp_kses_post( $this->format_breakdown( $q ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Build a human breakdown string with links per value.
	 *
	 * @param array $q Question report.
	 * @return string
	 */
	private function format_breakdown( array $q ) {
		if ( empty( $q['breakdown'] ) ) {
			return '<em>' . esc_html__( 'No responses', 'autorizenter' ) . '</em>';
		}
		$parts = array();
		foreach ( $q['breakdown'] as $value => $info ) {
			$label   = $this->display_value( $q['type'], (string) $value );
			$link    = $this->detail_link( $q['id'], (string) $value );
			$parts[] = '<a href="' . esc_url( $link ) . '">' . esc_html( $label ) . ': ' . (int) $info['count'] . '</a>';
		}
		return implode( ' &nbsp;|&nbsp; ', $parts );
	}

	/**
	 * Render the respondent detail list for a question (optionally a value).
	 *
	 * @param string $question_id Question id.
	 * @param string $value       Optional value filter.
	 * @return void
	 */
	private function render_detail( $question_id, $value ) {
		// Defence in depth: these arrive from $_GET, so restrict and escape explicitly.
		$question_id = sanitize_key( $question_id );
		$value       = sanitize_text_field( $value );
		$people      = $this->reports->respondents( $question_id, '' !== $value ? $value : null );

		$heading = esc_html( $question_id );
		if ( '' !== $value ) {
			$heading .= ' = ' . esc_html( $value );
		}
		?>
		<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=autorizenter-report' ) ); ?>">&larr; <?php esc_html_e( 'Back to summary', 'autorizenter' ); ?></a></p>
		<h2><?php echo wp_kses_post( $heading ); ?> <span class="count">(<?php echo (int) count( $people ); ?>)</span></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'autorizenter' ); ?></th>
					<th><?php esc_html_e( 'Email', 'autorizenter' ); ?></th>
					<th><?php esc_html_e( 'Answer', 'autorizenter' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $people as $p ) : ?>
				<tr>
					<td><?php echo esc_html( $p['name'] ); ?></td>
					<td><?php echo esc_html( $p['email'] ); ?></td>
					<td><?php echo esc_html( $p['value'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $people ) ) : ?>
				<tr><td colspan="3"><em><?php esc_html_e( 'No respondents.', 'autorizenter' ); ?></em></td></tr>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Stream a CSV of the full answer matrix.
	 *
	 * @return void
	 */
	public function export_csv() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'autorizenter' ) );
		}
		check_admin_referer( 'autorizenter_export_answers' );

		$matrix    = $this->reports->matrix();
		$questions = $matrix['questions'];

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=autorizenter-answers-' . gmdate( 'Ymd-His' ) . '.csv' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// UTF-8 BOM so Excel reads Thai correctly.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

		$header = array( 'user_id', 'name', 'email' );
		foreach ( $questions as $q ) {
			$header[] = $q['id'];
		}
		fputcsv( $out, $header ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv

		foreach ( $matrix['rows'] as $row ) {
			$line = array( $row['id'], $row['name'], $row['email'] );
			foreach ( $questions as $q ) {
				$val = isset( $row['answers'][ $q['id'] ] ) ? $row['answers'][ $q['id'] ] : '';
				if ( is_bool( $val ) ) {
					$val = $val ? '1' : '0';
				}
				$line[] = $val;
			}
			fputcsv( $out, $line ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Build a link to the detail view.
	 *
	 * @param string $question_id Question id.
	 * @param string $value       Optional value.
	 * @return string
	 */
	private function detail_link( $question_id, $value = '' ) {
		$args = array(
			'page'     => 'autorizenter-report',
			'question' => $question_id,
		);
		if ( '' !== $value ) {
			$args['value'] = $value;
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Human label for a stored value.
	 *
	 * @param string $type  Question type.
	 * @param string $value Stored value.
	 * @return string
	 */
	private function display_value( $type, $value ) {
		if ( 'checkbox' === $type ) {
			return '1' === $value ? __( 'Yes', 'autorizenter' ) : __( 'No', 'autorizenter' );
		}
		return '' === $value ? __( '(blank)', 'autorizenter' ) : $value;
	}
}
