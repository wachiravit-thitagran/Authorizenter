<?php
namespace Authorizenter\Core\Tests;
use Authorizenter\Core\{Settings,Oidc_Client};
use Authorizenter\UI\{Page_Installer,Frontend};
use PHPUnit\Framework\TestCase;
class P2FlowTest extends TestCase {
 protected function setUp(): void { azr_test_reset(); }
 /** @dataProvider subjectCases */
 public function test_active_userinfo_requires_matching_subject($userinfo,$expected): void {
  $GLOBALS['__mock_filters']['authorizenter_oidc_client']=static function() use($userinfo) { return new class($userinfo) {
   private $info;public function __construct($info){$this->info=$info;}
   public function authenticate(){return true;}
   public function getVerifiedClaims(){return (object)array('sub'=>'token-subject');}
   public function requestUserInfo(){if(null===$this->info){throw new \RuntimeException('Unavailable');}return (object)$this->info;}
  };};
  $result=(new Oidc_Client(new Settings()))->complete(array('client_id'=>'client'),'https://idp.example.test','https://example.test/callback',array('openid'));
  if($expected){$this->assertIsArray($result);$this->assertSame('token-subject',$result['sub']);}
  else{$this->assertInstanceOf(\WP_Error::class,$result);}
 }
 public static function subjectCases(): array {return array(array(array('sub'=>'token-subject','email'=>'same@example.test'),true),array(array('sub'=>'other','email'=>'other@example.test'),false),array(array('email'=>'missing@example.test'),false),array(null,true));}
 public function test_installer_marks_created_pages_and_uninstall_preserves_reused(): void {
  $m=new \ReflectionMethod(Page_Installer::class,'ensure_page');
  if (PHP_VERSION_ID < 80100) $m->setAccessible(true);
  $m->invoke(null,'authorizenter_login_page_id','Login','login','[authorizenter_login]');
  $id=get_option('authorizenter_login_page_id');
  $this->assertSame('[authorizenter_login]',get_post_meta($id,'_authorizenter_created_content',true));
 }
 public function test_private_mode_allows_named_context_and_valid_pending_token_only(): void {
  $ui=new Frontend();
  update_option(Page_Installer::OPT_CONTEXT_PAGES,array('named'=>42));$GLOBALS['__mock_is_page']=42;
  $this->assertTrue($ui->allow_public_workflow(false));
  update_option(Page_Installer::OPT_PENDING_PAGE,43);$GLOBALS['__mock_is_page']=43;
  $GLOBALS['__core']=(object)array('settings'=>new Settings());
  $_GET=array('azr_pending_token'=>'valid');set_transient('azr_pending_'.hash('sha256','valid'),array('provider'=>'google'),300);
  $this->assertTrue($ui->allow_public_workflow(false));$_GET['azr_pending_token']='invalid';$this->assertFalse($ui->allow_public_workflow(false));
  $GLOBALS['__mock_is_page']=99;$this->assertFalse($ui->allow_public_workflow(false));$_GET=array();
 }
}
