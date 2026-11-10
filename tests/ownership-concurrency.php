<?php
/** Local MySQL integration probe. Creates and drops its own random database. */
define('ABSPATH', __DIR__);
define('WP_UNINSTALL_PLUGIN',true);
class WP_Error { private $code; public function __construct($code,...$unused){$this->code=$code;} public function get_error_code(){return $this->code;} }
function __($text,$domain=''){return $text;}
function wp_cache_delete($key,$group=''){return true;}
class WP_User { public $ID; public function __construct($id){$this->ID=(int)$id;} }
function is_wp_error($value){return $value instanceof WP_Error;}
$dsn=getenv('AZR_TEST_MYSQL_DSN') ?: 'mysql:host=localhost';
$user=getenv('AZR_TEST_MYSQL_USER') ?: 'root';
$password=getenv('AZR_TEST_MYSQL_PASSWORD') ?: '';
$db='azr_p2_'.bin2hex(random_bytes(6));
function connect_test(){global $dsn,$user,$password;return new PDO($dsn,$user,$password,array(PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION));}
class Probe_DB {
 public $usermeta;public $options;public $pdo;
 public function __construct($db){$this->pdo=connect_test();$this->pdo->exec('USE `'.$db.'`');$this->usermeta=$db.'.usermeta';$this->options=$db.'.options';}
 public function prepare($sql,...$args){foreach($args as $a){$sql=preg_replace('/%s/',$this->pdo->quote($a),$sql,1);}return $sql;}
 public function query($sql){return $this->pdo->exec($sql);}
 public function get_var($sql){return $this->pdo->query($sql)->fetchColumn();}
}
function get_user_meta($id,$key,$single){global $wpdb;$s=$wpdb->pdo->prepare('SELECT meta_value FROM usermeta WHERE user_id=? AND meta_key=?');$s->execute(array($id,$key));return $s->fetchColumn() ?: '';}
function get_users($args){global $wpdb;$s=$wpdb->pdo->prepare('SELECT user_id FROM usermeta WHERE meta_key=? AND meta_value=? LIMIT 2');$s->execute(array($args['meta_key'],$args['meta_value']));$ids=$s->fetchAll(PDO::FETCH_COLUMN);return ($args['fields']??'all')==='ids'?$ids:array_map(static fn($id)=>new WP_User($id),$ids);}
function update_user_meta($id,$key,$value){global $wpdb;usleep(250000);$s=$wpdb->pdo->prepare('INSERT INTO usermeta (user_id,meta_key,meta_value) VALUES (?,?,?)');return $s->execute(array($id,$key,$value));}
function delete_option($key){global $wpdb;$s=$wpdb->pdo->prepare('DELETE FROM options WHERE option_name=?');return $s->execute(array($key));}
require dirname(__DIR__).'/plugins/authorizenter-core/includes/class-user-mapper.php';
$admin=connect_test();$admin->exec('CREATE DATABASE `'.$db.'`');
$dir=sys_get_temp_dir().'/'.$db;mkdir($dir);
try {
 $admin->exec('CREATE TABLE `'.$db.'`.usermeta (user_id BIGINT, meta_key VARCHAR(255), meta_value TEXT)');
 $pids=array();
 foreach(array(17,18) as $id){$pid=pcntl_fork();if($pid===0){$admin=null;$wpdb=new Probe_DB($db);touch($dir.'/ready-'.$id);while(!file_exists($dir.'/go')){usleep(1000);}$result=\Authorizenter\Core\User_Mapper::claim_link($id,'oidc','concurrent-subject');file_put_contents($dir.'/result-'.$id,json_encode($result===true?'linked':$result->get_error_code()));exit(0);}$pids[]=$pid;}
 while(count(glob($dir.'/ready-*'))<2){usleep(1000);}touch($dir.'/go');foreach($pids as $pid){pcntl_waitpid($pid,$status);if($status!==0){throw new RuntimeException('Worker failed');}}
 // Forked PDO destructors can invalidate the parent's connection; reconnect.
 $admin=connect_test();$wpdb=new Probe_DB($db);
 $results=array_map(static fn($p)=>json_decode(file_get_contents($p),true),glob($dir.'/result-*'));sort($results);
 if($results!==array('authorizenter_link_busy','linked')){throw new RuntimeException(json_encode($results));}
 $rows=$wpdb->pdo->query('SELECT user_id,meta_value FROM usermeta')->fetchAll(PDO::FETCH_ASSOC);
 if(count($rows)!==1){throw new RuntimeException('Subject has multiple owners');}
 $mapper=(new ReflectionClass(\Authorizenter\Core\User_Mapper::class))->newInstanceWithoutConstructor();$find=new ReflectionMethod($mapper,'find_by_link');$resolved=$find->invoke($mapper,'oidc','concurrent-subject');
 if($resolved->ID!==(int)$rows[0]['user_id']){throw new RuntimeException('Nondeterministic subject resolution');}
 $loser=(int)$rows[0]['user_id']===17?18:17;$conflict=\Authorizenter\Core\User_Mapper::claim_link($loser,'oidc','concurrent-subject');
 if(!is_wp_error($conflict)||$conflict->get_error_code()!=='authorizenter_link_taken'){throw new RuntimeException('Later conflict was accepted');}
 $admin->exec('CREATE TABLE `'.$db.'`.options (option_name VARCHAR(255), option_value TEXT)');
 $wpdb->pdo->exec("INSERT INTO options VALUES ('authorizenter_settings','settings'),('_transient_azr_pending_synthetic','private'),('_transient_timeout_azr_pending_synthetic','300'),('unrelated_option','keep')");
 $wpdb->pdo->exec("INSERT INTO usermeta VALUES (17,'authorizenter_provider_data_oidc','synthetic private claims'),(17,'unrelated_meta','keep')");
 require dirname(__DIR__).'/plugins/authorizenter-core/uninstall.php';
 $remaining_options=$wpdb->pdo->query('SELECT option_name FROM options')->fetchAll(PDO::FETCH_COLUMN);$remaining_meta=$wpdb->pdo->query('SELECT meta_key FROM usermeta')->fetchAll(PDO::FETCH_COLUMN);
 if($remaining_options!==array('unrelated_option')||$remaining_meta!==array('unrelated_meta')){throw new RuntimeException('Uninstall retained identity data or removed unrelated data');}
 echo json_encode(array('uninstall'=>'raw claims and pending tokens removed; unrelated values retained','workers'=>$results,'owners'=>count($rows),'resolved_user'=>$resolved->ID,'later_conflict'=>$conflict->get_error_code()),JSON_PRETTY_PRINT).PHP_EOL;
} finally {
 $admin=connect_test();$admin->exec('DROP DATABASE `'.$db.'`');foreach(glob($dir.'/*') as $file){unlink($file);}rmdir($dir);
}
