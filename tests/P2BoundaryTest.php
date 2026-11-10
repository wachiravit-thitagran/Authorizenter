<?php
namespace Authorizenter\Core\Tests;
use Authorizenter\Core\{Settings,Questions,Provider_Registry,Rest_Api,OAuth_Engine,Org_Policy,User_Mapper,Identity};
use Authorizenter\UI\Frontend;
use PHPUnit\Framework\TestCase;
class P2BoundaryTest extends TestCase {
 protected function setUp(): void {azr_test_reset();}
 /** @dataProvider destinations */
 public function test_questions_destination_is_validated($destination,$expected): void {
  $s=new Settings();$GLOBALS['__core']=(object)array('settings'=>$s,'questions'=>new Questions($s));$GLOBALS['__logged_in']=true;
  $_GET=array('return_to'=>$destination);$html=(new Frontend())->render_questions(array());$_GET=array();
  $this->assertStringContainsString('data-return-to="'.esc_attr($expected).'"',$html);
 }
 public static function destinations(): array {return array(array('https://external.invalid/path','https://example.test/'),array('//external.invalid/path','https://example.test/'),array('/course/?a=1&b=2','/course/?a=1&b=2'),array('https://example.test/course/?a=1&b=2','https://example.test/course/?a=1&b=2'));}
 /** @runInSeparateProcess
  * @preserveGlobalState disabled
  */
 public function test_safe_redirect_fallback_never_emits_external_destination(): void {
  $process=proc_open(array(PHP_BINARY,__DIR__.'/fixtures/safe-redirect.php'),array(1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
  $body=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$this->assertSame(0,proc_close($process),$error);
  $this->assertStringNotContainsString('external.invalid',$body);$this->assertStringContainsString('https://example.test/',$body);
 }
 public function test_uninstall_preserves_reused_and_customized_pages_including_pending(): void {
  if(!defined('WP_UNINSTALL_PLUGIN')){define('WP_UNINSTALL_PLUGIN',true);}
  $ids=array();foreach(array('reused','created','customized','pending') as $name){$ids[$name]=wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_content'=>'['.$name.']'));}
  update_post_meta($ids['created'],'_authorizenter_created_content','[created]');update_post_meta($ids['customized'],'_authorizenter_created_content','[original]');update_post_meta($ids['pending'],'_authorizenter_created_content','[pending]');
  update_option('authorizenter_login_page_id',$ids['reused']);update_option('authorizenter_questions_page_id',$ids['created']);update_option('authorizenter_pending_page_id',$ids['pending']);update_option('authorizenter_context_pages',array('named'=>$ids['customized']));
  require dirname(__DIR__).'/plugins/authorizenter-ui/uninstall.php';
  $this->assertNotNull(get_post($ids['reused']));$this->assertNotNull(get_post($ids['customized']));$this->assertNull(get_post($ids['created']));$this->assertNull(get_post($ids['pending']));$this->assertFalse(get_option('authorizenter_pending_page_id'));
 }
}
