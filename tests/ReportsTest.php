<?php
/**
 * Tests for the Reports aggregator (counts, breakdown, respondents, matrix).
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Questions;
use Autorizenter\Core\Reports;
use PHPUnit\Framework\TestCase;

class ReportsTest extends TestCase {

	/** @var Questions */
	private $questions;

	/** @var Reports */
	private $reports;

	protected function setUp(): void {
		azr_test_reset();
		update_option(
			Settings::OPTION,
			array(
				'questions' => array(
					// Non-required so a "no" (false) answer is recorded rather than rejected.
					array( 'id' => 'is_bia_volunteer', 'type' => 'checkbox', 'label' => 'Volunteer?', 'required' => false ),
					array( 'id' => 'faculty', 'type' => 'radio', 'label' => 'Faculty', 'required' => false, 'options' => array( 'Science', 'Arts' ) ),
				),
			)
		);
		$this->questions = new Questions( new Settings() );
		$this->reports   = new Reports( $this->questions );

		// Three users answer.
		azr_test_make_user( 1, array( 'read' => true ), 'a@psu.ac.th', 'Alice' );
		azr_test_make_user( 2, array( 'read' => true ), 'b@psu.ac.th', 'Bob' );
		azr_test_make_user( 3, array( 'read' => true ), 'c@psu.ac.th', 'Carol' );

		$this->questions->save_answers( 1, array( 'is_bia_volunteer' => '1', 'faculty' => 'Science' ) );
		$this->questions->save_answers( 2, array( 'is_bia_volunteer' => '1', 'faculty' => 'Arts' ) );
		$this->questions->save_answers( 3, array( 'is_bia_volunteer' => '0', 'faculty' => 'Science' ) );
	}

	public function test_checkbox_answered_counts_only_true(): void {
		$summary = $this->reports->summary();
		$vol     = $this->by_id( $summary, 'is_bia_volunteer' );

		$this->assertSame( 2, $vol['answered'] ); // users 1 and 2.
		$this->assertSame( 2, $vol['breakdown']['1']['count'] );
		$this->assertSame( 1, $vol['breakdown']['0']['count'] );
	}

	public function test_radio_breakdown(): void {
		$summary = $this->reports->summary();
		$fac     = $this->by_id( $summary, 'faculty' );

		$this->assertSame( 2, $fac['breakdown']['Science']['count'] );
		$this->assertSame( 1, $fac['breakdown']['Arts']['count'] );
		$this->assertSame( 3, $fac['answered'] ); // all non-empty.
	}

	public function test_respondents_filtered_by_value(): void {
		$yes = $this->reports->respondents( 'is_bia_volunteer', '1' );
		$this->assertCount( 2, $yes );
		$names = array_map( static fn( $r ) => $r['name'], $yes );
		sort( $names );
		$this->assertSame( array( 'Alice', 'Bob' ), $names );
	}

	public function test_respondents_all_when_no_value(): void {
		$all = $this->reports->respondents( 'is_bia_volunteer' );
		$this->assertCount( 3, $all );
	}

	public function test_matrix_has_columns_and_rows(): void {
		$matrix = $this->reports->matrix();
		$this->assertCount( 2, $matrix['questions'] );
		$this->assertCount( 3, $matrix['rows'] );

		$row = $matrix['rows'][0];
		$this->assertArrayHasKey( 'answers', $row );
		$this->assertArrayHasKey( 'email', $row );
	}

	public function test_question_with_no_responses(): void {
		$rep = $this->reports->question_report(
			array( 'id' => 'never_answered', 'type' => 'text', 'label' => 'X', 'required' => false )
		);
		$this->assertSame( 0, $rep['answered'] );
		$this->assertSame( array(), $rep['breakdown'] );
		$this->assertCount( 0, $this->reports->respondents( 'never_answered' ) );
	}

	private function by_id( array $summary, string $id ): array {
		foreach ( $summary as $q ) {
			if ( $q['id'] === $id ) {
				return $q;
			}
		}
		$this->fail( "Question $id not found in summary" );
	}
}
