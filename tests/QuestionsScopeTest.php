<?php
namespace Authorizenter\Core\Tests;

use Authorizenter\Core\Questions;
use Authorizenter\Core\Settings;
use PHPUnit\Framework\TestCase;

class QuestionsScopeTest extends TestCase {

	protected function setUp(): void {
		azr_test_reset();
		update_option( Settings::OPTION, array( 'questions' => array(
			array( 'id' => 'google_only', 'label' => 'Google', 'required' => true, 'providers' => array( 'google' ) ),
			array( 'id' => 'line_only', 'label' => 'LINE', 'required' => true, 'providers' => array( 'line' ) ),
			array( 'id' => 'common', 'label' => 'Common', 'required' => true ),
		) ) );
		update_user_meta( 7, 'authorizenter_last_provider', 'google' );
	}

	public function test_save_uses_authenticated_provider_and_completes_that_scope(): void {
		$q = new Questions( new Settings() );
		$completed = array();
		$GLOBALS['__mock_actions']['authorizenter_questions_completed'] = function ( $id ) use ( &$completed ) { $completed[] = $id; };
		$this->assertTrue( $q->save_answers( 7, array( 'google_only' => 'yes', 'common' => 'yes' ) ) );
		$this->assertFalse( $q->has_pending_required( 7 ) );
		$this->assertSame( array(), $q->pending_for_user( 7 ) );
		$this->assertSame( array( 7 ), $completed );
		$this->assertArrayNotHasKey( 'line_only', $q->get_answers( 7 ) );
	}

	public function test_hidden_provider_input_is_rejected_without_mutation(): void {
		$q = new Questions( new Settings() );
		$result = $q->save_answers( 7, array( 'google_only' => 'yes', 'common' => 'yes', 'line_only' => 'injected' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'authorizenter_answer_out_of_scope', $result->get_error_code() );
		$this->assertSame( array(), $q->get_answers( 7 ) );
	}

	public function test_common_required_question_remains_required(): void {
		$q = new Questions( new Settings() );
		$result = $q->save_answers( 7, array( 'google_only' => 'yes' ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'common', $result->get_error_data()['question'] );
	}

	public function test_historical_other_provider_answers_are_preserved(): void {
		$q = new Questions( new Settings() );
		update_user_meta( 7, Questions::ANSWERS_META, array( 'line_only' => 'historical' ) );
		$this->assertTrue( $q->save_answers( 7, array( 'google_only' => 'yes', 'common' => 'yes' ) ) );
		$this->assertSame( 'historical', $q->get_answers( 7 )['line_only'] );
	}

	public function test_incomplete_migration_retains_pending_answers_for_retry(): void {
		$settings = new Settings();
		$all = $settings->all();
		$all['access']['pending_meta']['u@example.test'] = array( 'answers' => array( 'google_only' => 'yes' ) );
		$settings->save( $all );
		$q = new Questions( $settings );
		$q->migrate_pending_answers( azr_test_make_user( 7 ), 'google', new \Authorizenter\Core\Identity( 'google', array( 'email' => 'u@example.test' ) ) );
		$this->assertSame( array(), $q->get_answers( 7 ) );
		$this->assertArrayHasKey( 'u@example.test', $settings->all()['access']['pending_meta'] );
	}

	public function test_preapproval_migration_keeps_pending_data_until_success(): void {
		$settings = new Settings();
		$all = $settings->all();
		$all['access']['pending_meta']['u@example.test'] = array( 'answers' => array( 'google_only' => 'yes', 'line_only' => 'old', 'common' => 'yes' ) );
		$settings->save( $all );
		$q = new Questions( $settings );
		$user = azr_test_make_user( 7 );
		$q->migrate_pending_answers( $user, 'google', new \Authorizenter\Core\Identity( 'google', array( 'email' => 'u@example.test' ) ) );
		$this->assertSame( array( 'google_only' => 'yes', 'common' => 'yes' ), $q->get_answers( 7 ) );
		$this->assertArrayNotHasKey( 'u@example.test', $settings->all()['access']['pending_meta'] );
	}
}
