<?php
namespace Authorizenter\Core\Tests;
use Authorizenter\Core\{Settings,Questions,User_Mapper,Org_Policy,Identity};
use PHPUnit\Framework\TestCase;
class P2RegressionTest extends TestCase {
 protected function setUp(): void { azr_test_reset(); }
 public function test_verified_email_must_not_replace_existing_subject(): void {
  azr_test_make_user(7,array('read'=>true),'staff@example.test');
  update_user_meta(7,'authorizenter_link_oidc','original');
  $s=new Settings(); $m=new User_Mapper($s,new Org_Policy($s));
  $result=$m->resolve(new Identity('oidc',array('sub'=>'replacement','email'=>'staff@example.test','email_verified'=>true)));
  $this->assertInstanceOf(\WP_Error::class,$result);
  $this->assertSame('original',get_user_meta(7,'authorizenter_link_oidc',true));
 }
 /** @dataProvider requiredCases */
 public function test_clearing_required_answer_fails_and_legacy_invalid_is_pending($type,$answer,$invalid,$options): void {
  update_option(Settings::OPTION,array('questions'=>array(array('id'=>'required','label'=>'Required','type'=>$type,'required'=>true,'options'=>$options))));
  $q=new Questions(new Settings());
  update_user_meta(7,Questions::ANSWERS_META,array('required'=>$answer));
  $this->assertInstanceOf(\WP_Error::class,$q->save_answers(7,array('required'=>$invalid)));
  $this->assertSame($answer,$q->get_answers(7)['required']);
  update_user_meta(7,Questions::ANSWERS_META,array('required'=>$invalid));
  $this->assertTrue($q->has_pending_required(7));
 }
 public static function requiredCases(): array { return array(array('checkbox',true,false,array()),array('text','yes','',array()),array('select','yes','removed',array('yes'))); }
 public function test_unrelated_submission_retains_valid_required_value(): void {
  update_option(Settings::OPTION,array('questions'=>array(array('id'=>'required','label'=>'Required','required'=>true),array('id'=>'optional','label'=>'Optional'))));
  update_user_meta(7,Questions::ANSWERS_META,array('required'=>'valid'));
  $q=new Questions(new Settings()); $this->assertTrue($q->save_answers(7,array('optional'=>'new')));
 }
 /** @dataProvider csvCases */
 public function test_csv_cells_are_spreadsheet_safe($raw,$safe): void {
  require_once dirname(__DIR__).'/plugins/authorizenter-core/includes/class-admin-reports.php';
  $this->assertSame($safe,\Authorizenter\Core\Admin_Reports::csv_cell($raw));
 }
 public static function csvCases(): array { return array(array('=HYPERLINK("x")',"'=HYPERLINK(\"x\")"),array("\t @evil","'\t @evil"),array('-123',"'-123"),array('ภาษาไทย','ภาษาไทย'),array("a,b\nc","a,b\nc")); }
 public function test_duplicate_legacy_subject_fails_closed(): void {
  azr_test_make_user(7);azr_test_make_user(8);
  update_user_meta(7,'authorizenter_link_oidc','duplicate');update_user_meta(8,'authorizenter_link_oidc','duplicate');
  $s=new Settings();$this->assertInstanceOf(\WP_Error::class,(new User_Mapper($s,new Org_Policy($s)))->resolve(new Identity('oidc',array('sub'=>'duplicate'))));
 }
 public function test_unavailable_lock_and_failed_persistence_fail_closed(): void {
  $GLOBALS['__mock_db_get_var']=static function($query){return 0;};
  $result=User_Mapper::claim_link(7,'oidc','subject');$this->assertSame('authorizenter_link_busy',$result->get_error_code());$this->assertSame('',get_user_meta(7,'authorizenter_link_oidc',true));
  unset($GLOBALS['__mock_db_get_var']);$GLOBALS['__mock_meta_write_failure']=true;
  $result=User_Mapper::claim_link(7,'oidc','subject');$this->assertSame('authorizenter_link_write_failed',$result->get_error_code());$this->assertSame('',get_user_meta(7,'authorizenter_link_oidc',true));
 }

}
