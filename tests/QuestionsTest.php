<?php
/**
 * Tests for the customizable Questions system.
 *
 * @package Autorizenter\Core\Tests
 */

namespace Autorizenter\Core\Tests;

use Autorizenter\Core\Settings;
use Autorizenter\Core\Questions;
use PHPUnit\Framework\TestCase;

class QuestionsTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
	}

	private function questions_with( array $defs ): Questions {
		update_option( Settings::OPTION, array( 'questions' => $defs ) );
		return new Questions( new Settings() );
	}

	public function test_sanitize_definition_rejects_missing_fields(): void {
		$q = new Questions( new Settings() );
		$this->assertNull( $q->sanitize_definition( array( 'type' => 'text' ) ) ); // no id/label.
	}

	public function test_sanitize_definition_defaults_unknown_type_to_text(): void {
		$q     = new Questions( new Settings() );
		$clean = $q->sanitize_definition( array( 'id' => 'q1', 'label' => 'Q', 'type' => 'bogus' ) );
		$this->assertSame( 'text', $clean['type'] );
	}

	public function test_required_checkbox_must_be_checked(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'agree', 'type' => 'checkbox', 'label' => 'Agree?', 'required' => true ),
			)
		);

		// Not provided / unchecked -> error.
		$result = $q->save_answers( 1, array() );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'autorizenter_answer_required', $result->get_error_code() );
	}

	public function test_checked_checkbox_saves_and_clears_pending(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'agree', 'type' => 'checkbox', 'label' => 'Agree?', 'required' => true ),
			)
		);

		$this->assertTrue( $q->save_answers( 1, array( 'agree' => '1' ) ) );
		$this->assertFalse( $q->has_pending_required( 1 ) );
		$this->assertSame( array( 'agree' => true ), $q->get_answers( 1 ) );
	}

	public function test_pending_excludes_answered_questions(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'a', 'type' => 'text', 'label' => 'A', 'required' => true ),
				array( 'id' => 'b', 'type' => 'text', 'label' => 'B', 'required' => false ),
			)
		);

		$q->save_answers( 1, array( 'a' => 'done' ) );
		$pending = $q->pending_for_user( 1 );

		$ids = array_map( static fn( $x ) => $x['id'], $pending );
		$this->assertSame( array( 'b' ), $ids );
	}

	public function test_radio_accepts_valid_option_only(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'fac', 'type' => 'radio', 'label' => 'Faculty', 'required' => true, 'options' => array( 'Science', 'Arts' ) ),
			)
		);

		$this->assertTrue( $q->save_answers( 1, array( 'fac' => 'Science' ) ) );
		$this->assertSame( array( 'fac' => 'Science' ), $q->get_answers( 1 ) );
	}

	public function test_radio_rejects_value_outside_options(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'fac', 'type' => 'radio', 'label' => 'Faculty', 'required' => true, 'options' => array( 'Science', 'Arts' ) ),
			)
		);

		// "Engineering" is not an allowed option -> treated as empty -> required fails.
		$result = $q->save_answers( 1, array( 'fac' => 'Engineering' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
	}

	public function test_select_value_validated_against_options(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'yr', 'type' => 'select', 'label' => 'Year', 'required' => false, 'options' => array( '1', '2' ) ),
			)
		);

		$q->save_answers( 1, array( 'yr' => '9' ) ); // invalid -> stored empty.
		$this->assertSame( '', $q->get_answers( 1 )['yr'] );
	}

	public function test_mirror_meta_written_for_reports(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'agree', 'type' => 'checkbox', 'label' => 'Agree?', 'required' => true ),
			)
		);

		$q->save_answers( 1, array( 'agree' => '1' ) );
		$this->assertSame( '1', get_user_meta( 1, 'autorizenter_answer_agree', true ) );
	}

	public function test_provider_scoped_question_is_filtered(): void {
		$q = $this->questions_with(
			array(
				array( 'id' => 'g', 'type' => 'text', 'label' => 'G', 'required' => true, 'providers' => array( 'google' ) ),
			)
		);

		$this->assertCount( 1, $q->pending_for_user( 1, 'google' ) );
		$this->assertCount( 0, $q->pending_for_user( 1, 'line' ) );
	}
}
