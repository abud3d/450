<?php
declare(strict_types=1);

$base = rtrim((string)getenv('AUTH_TEST_BASE'),'/');
$mailFile = (string)getenv('MAIL_CAPTURE');
if ($base === '' || $mailFile === '') throw new RuntimeException('auth test environment missing');
function authCheck(bool $ok,string $message):void { if(!$ok) throw new RuntimeException($message); }
function authRequest(string $method,string $path,?array $body=null,string $cookie='',string $csrf=''):array {
    global $base;
    $headers=[];
    $ch=curl_init($base.$path);
    $requestHeaders=['Origin: '.$base,'Content-Type: application/json'];
    if($cookie!=='')$requestHeaders[]='Cookie: '.$cookie;
    if($csrf!=='')$requestHeaders[]='X-CSRF-Token: '.$csrf;
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$requestHeaders,
        CURLOPT_HEADERFUNCTION=>function($curl,$line)use(&$headers){$length=strlen($line);$parts=explode(':',$line,2);if(count($parts)===2)$headers[strtolower(trim($parts[0]))][]=trim($parts[1]);return $length;},
    ]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($body,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    $raw=curl_exec($ch);
    if($raw===false){$error=curl_error($ch);curl_close($ch);throw new RuntimeException('HTTP request failed: '.$error);}
    $status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);curl_close($ch);
    $data=json_decode($raw,true);
    if(!is_array($data))throw new RuntimeException('auth endpoint returned invalid JSON: '.$raw);
    $cookies=[];
    foreach($headers['set-cookie']??[] as $line){$pair=explode(';',$line,2)[0];$parts=explode('=',$pair,2);if(count($parts)===2)$cookies[$parts[0]]=$parts[1];}
    return ['status'=>$status,'data'=>$data,'cookies'=>$cookies,'headers'=>$headers];
}
authCheck(extension_loaded('curl'),'curl extension is required for HTTP auth integration');
$email='qa+'.bin2hex(random_bytes(4)).'@example.test';
$password='Abc123';
$shortPassword=authRequest('POST','/api/auth.php?action=register',['email'=>'short+'.bin2hex(random_bytes(3)).'@example.test','password'=>'Ab123','displayName'=>'QA Short']);
authCheck($shortPassword['status']===422,'password shorter than six characters is rejected');
$register=authRequest('POST','/api/auth.php?action=register',['email'=>$email,'password'=>$password,'displayName'=>'QA Player']);
authCheck($register['status']===201 && ($register['data']['ok']??false)===true,'register endpoint must create account and hand email to mail transport');
$guest=$register['cookies']['baloot_guest']??'';
authCheck((bool)preg_match('/^[a-f0-9]{64}$/',$guest),'registration sets a persistent guest identity');
$beforeVerify=authRequest('POST','/api/auth.php?action=login',['email'=>$email,'password'=>$password],'baloot_guest='.$guest);
authCheck($beforeVerify['status']===403,'unverified account cannot log in');
$duplicate=authRequest('POST','/api/auth.php?action=register',['email'=>$email,'password'=>$password,'displayName'=>'Duplicate'],'baloot_guest='.$guest);
authCheck($duplicate['status']===409,'duplicate email is rejected');

$mail=@file_get_contents($mailFile);
authCheck(is_string($mail)&&$mail!=='','mail() must invoke the configured sendmail transport');
authCheck(str_contains($mail,'To: '.$email),'captured activation message must target the registered address');
authCheck(preg_match('~https://example\\.test/api/auth\\.php\\?action=verify&token=([a-f0-9]{64})~',$mail,$match)===1,'activation URL must contain a 64-character token');
$verify=authRequest('GET','/api/auth.php?action=verify&token='.$match[1]);
authCheck($verify['status']===200 && ($verify['data']['ok']??false)===true,'activation token must verify the account');
$verifyAgain=authRequest('GET','/api/auth.php?action=verify&token='.$match[1]);
authCheck($verifyAgain['status']===400,'activation token is single use');

$badLogin=authRequest('POST','/api/auth.php?action=login',['email'=>$email,'password'=>'wrong-password'],'baloot_guest='.$guest);
authCheck($badLogin['status']===401,'wrong password is rejected');
$login=authRequest('POST','/api/auth.php?action=login',['email'=>$email,'password'=>$password],'baloot_guest='.$guest);
authCheck($login['status']===200 && ($login['data']['ok']??false)===true,'verified user can log in');
authCheck(($login['data']['user']['guestId']??'')===$guest,'account retains the original guest identity');
$session=$login['cookies']['b450_session']??'';
authCheck((bool)preg_match('/^[a-f0-9]{64}$/',$session),'login sets an HttpOnly session cookie');

$me=authRequest('POST','/api/auth.php?action=me',null,'b450_session='.$session);
authCheck($me['status']===200 && ($me['data']['user']['email']??'')===$email,'session restores account on another browser profile');
authCheck(($me['cookies']['baloot_guest']??'')===$guest,'session restores the saved guest identity without the old guest cookie');

$profile=authRequest('POST','/api/auth.php?action=profile',['displayName'=>'QA Profile','avatar'=>'🏆','bio'=>'لاعب تجريبي'],'b450_session='.$session,(string)$login['data']['csrf']);
authCheck($profile['status']===200 && ($profile['data']['user']['displayName']??'')==='QA Profile','profile update saves the player display name');
authCheck(($profile['data']['user']['avatar']??'')==='🏆','profile update saves the avatar');
authCheck(($profile['data']['user']['stats']['points']??-1)===0,'new player starts with zero accumulated points');

$forgot=authRequest('POST','/api/auth.php?action=forgot',['email'=>$email]);
authCheck($forgot['status']===200,'password recovery request is accepted');
$mail=@file_get_contents($mailFile);
authCheck(is_string($mail)&&preg_match('~https://example\\.test/reset-password\\.html\\?reset=([a-f0-9]{64})~',$mail,$resetMatch)===1,'recovery email contains a single-use reset link');
$reset=authRequest('POST','/api/auth.php?action=reset',['token'=>$resetMatch[1],'password'=>'Xyz789']);
authCheck($reset['status']===200,'valid reset token changes the password');
$oldPassword=authRequest('POST','/api/auth.php?action=login',['email'=>$email,'password'=>$password],'baloot_guest='.$guest);
authCheck($oldPassword['status']===401,'old password stops working after reset');
$newLogin=authRequest('POST','/api/auth.php?action=login',['email'=>$email,'password'=>'NewTestPassword!2026'],'baloot_guest='.$guest);
authCheck($newLogin['status']===200,'new password can log in');
$newSession=$newLogin['cookies']['b450_session']??'';
authCheck((bool)preg_match('/^[a-f0-9]{64}$/',$newSession),'password reset invalidates old sessions and allows a fresh session');
$logout=authRequest('POST','/api/auth.php?action=logout',null,'b450_session='.$newSession,(string)$newLogin['data']['csrf']);
authCheck($logout['status']===200,'logout succeeds');
$afterLogout=authRequest('POST','/api/auth.php?action=me',null,'b450_session='.$newSession);
authCheck($afterLogout['status']===401,'logged-out session is invalidated');

$app=(string)file_get_contents(__DIR__.'/../public/app.js');
foreach(['/api/auth.php?action=register','/api/auth.php?action=login','/api/auth.php?action=me','/api/auth.php?action=logout','/api/auth.php?action=forgot','/api/auth.php?action=reset','/api/auth.php?action=profile'] as $endpoint) {
    authCheck(str_contains($app,$endpoint),'browser UI must call '.$endpoint);
}
authCheck(str_contains($app,'account?.guestId'),'browser UI must restore guest identity from account session');
echo "PHP account, profile, password recovery and mail integration tests passed (mail transport captured; no external email sent)\\n";
