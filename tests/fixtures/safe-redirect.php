<?php
require dirname(__DIR__).'/bootstrap.php';
$s=new \Authorizenter\Core\Settings();$p=new \Authorizenter\Core\Provider_Registry($s);$q=new \Authorizenter\Core\Questions($s);$u=new \Authorizenter\Core\User_Mapper($s,new \Authorizenter\Core\Org_Policy($s));$e=new \Authorizenter\Core\OAuth_Engine($s,$p,new \Authorizenter\Core\Org_Policy($s),$u);$api=new \Authorizenter\Core\Rest_Api($e,$p,$q,$s,new \Authorizenter\Core\Reports($q));
echo 'headers already sent';
$m=new ReflectionMethod($api,'redirect_to');if (PHP_VERSION_ID < 80100) $m->setAccessible(true);
$m->invoke($api,'https://external.invalid/path',true);
