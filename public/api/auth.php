<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

function done(int $status, array $data): never {
 http_response_code($status);
 echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
 exit;
}
function body(): array {
 if ((int)($_SERVER['CONTENT_LENGTH']??0)>8192) done(413,['ok'=>false,'message'=>'حجم الطلب كبير']);
 $v=json_decode(file_get_contents('php://input')?:'{}',true);
 if (!is_array($v)) done(400,['ok'=>false,'message'=>'طلب غير صالح']);
 return $v;
}
function db(callable $fn): mixed {
 $dir=(string)getenv('BALOUT_DATA_DIR');
 if (!$dir||!is_dir($dir)||!is_writable($dir)) done(503,['ok'=>false,'message'=>'خدمة الحسابات غير مهيأة']);
 $dir=realpath($dir);
 $web=realpath(dirname(__DIR__));
 if (!$dir || ($web && ($dir===$web || str_starts_with($dir,$web.DIRECTORY_SEPARATOR)))) done(503,['ok'=>false,'message'=>'يجب حفظ بيانات الحسابات خارج مجلد الموقع']);
 $lock=fopen($dir.'/accounts.lock','c');
 if (!$lock||!flock($lock,LOCK_EX)) done(503,['ok'=>false,'message'=>'الخدمة مشغولة']);
 try {
  $path=$dir.'/accounts.json'; $raw=is_file($path)?file_get_contents($path):'';
  $data=$raw?json_decode($raw,true):null;
  if ($raw !== '' && !is_array($data)) throw new RuntimeException('corrupt account store');
  if (!is_array($data)) $data=['users'=>[],'sessions'=>[],'limits'=>[]];
  $result=$fn($data);
  $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $tmp=tempnam($dir,'b450-');
  if ($json===false||!$tmp||file_put_contents($tmp,$json,LOCK_EX)===false||!rename($tmp,$path)) throw new RuntimeException('write failed');
  @chmod($path,0600);
  return $result;
 } catch (Throwable $e) {
  error_log('B450 storage: '.$e->getMessage());
  done(500,['ok'=>false,'message'=>'تعذر إتمام العملية']);
 } finally { flock($lock,LOCK_UN); fclose($lock); }
}
function origin_guard(): void {
 $expected=rtrim((string)getenv('APP_ORIGIN'),'/');
 $origin=rtrim((string)($_SERVER['HTTP_ORIGIN']??''),'/');
 if (!$expected||!$origin||!hash_equals($expected,$origin)) done(403,['ok'=>false,'message'=>'مصدر الطلب غير مسموح']);
}
function limited(array &$d,string $name,int $max,int $window): bool {
 $key=hash('sha256',$name.'|'.($_SERVER['REMOTE_ADDR']??'unknown')); $now=time();
 $r=$d['limits'][$key]??['time'=>$now,'count'=>0];
 if ($now-(int)$r['time']>=$window) $r=['time'=>$now,'count'=>0];
 $r['count']++; $d['limits'][$key]=$r; return $r['count']>$max;
}
function randtoken(): string { return bin2hex(random_bytes(32)); }
function setauthcookie(string $token,int $expires): void {
 setcookie('b450_session',$token,['expires'=>$expires,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function clearcookie(): void {
 setcookie('b450_session','',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function setguestcookie(string $guestId): void {
 setcookie('baloot_guest',$guestId,['expires'=>time()+15552000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function currentguestid(): string {
 $guestId=$_COOKIE['baloot_guest']??'';
 if (!is_string($guestId)||!preg_match('/^[a-f0-9]{64}$/',$guestId)) $guestId=bin2hex(random_bytes(32));
 setguestcookie($guestId);
 return $guestId;
}
function session_current(array &$d): ?array {
 $raw=$_COOKIE['b450_session']??'';
 if (!is_string($raw)||strlen($raw)!==64) return null;
 $key=hash('sha256',$raw); $s=$d['sessions'][$key]??null;
 if (!$s||(int)$s['expires']<time()) { unset($d['sessions'][$key]); return null; }
 $u=$d['users'][$s['uid']]??null;
 if (!$u||empty($u['verified'])) return null;
 return ['uid'=>$u['uid'],'email'=>$u['email'],'csrf'=>$s['csrf'],'guestId'=>$u['guestId']??null,'displayName'=>$u['displayName']??''];
}
function mailtoken(string $email,string $subject,string $url): bool {
 $from=(string)getenv('MAIL_FROM');
 if (!$from||!filter_var($from,FILTER_VALIDATE_EMAIL)) return false;
 $msg="Hello,\nUse this secure link within 30 minutes:\n".$url."\nIgnore if you did not request it.";
 return @mail($email,'=?UTF-8?B?'.base64_encode($subject).'?=',$msg,"From: ".$from."\r\nContent-Type: text/plain; charset=UTF-8");
}
function baseurl(): string {
 $v=rtrim((string)getenv('APP_URL'),'/');
 if (!str_starts_with($v,'https://')) done(503,['ok'=>false,'message'=>'إعداد HTTPS غير مكتمل']);
 return $v;
}
$action=(string)($_GET['action']??''); $method=(string)($_SERVER['REQUEST_METHOD']??'');
if ($action==='verify'&&$method==='GET') {
 $token=(string)($_GET['token']??'');
 if (!preg_match('/^[a-f0-9]{64}$/',$token)) done(400,['ok'=>false,'message'=>'رابط غير صالح']);
 $ok=db(function (&$d) use ($token) {
  $hash=hash('sha256',$token);
  foreach ($d['users'] as &$u) if (!empty($u['verify_hash'])&&hash_equals($u['verify_hash'],$hash)&&(int)$u['verify_exp']>=time()) {
   $u['verified']=true; unset($u['verify_hash'],$u['verify_exp']); return true;
  }
  return false;
 });
 done($ok?200:400,['ok'=>$ok,'message'=>$ok?'تم تفعيل الحساب':'الرابط منتهي أو غير صالح']);
}
if ($method!=='POST') done(405,['ok'=>false,'message'=>'طريقة الطلب غير مدعومة']);
origin_guard(); $in=body(); $guestId=currentguestid();

if ($action==='register') {
 $email=strtolower(trim((string)($in['email']??''))); $password=(string)($in['password']??''); $displayName=trim(strip_tags((string)($in['displayName']??''))); $displayName=mb_substr($displayName,0,40,'UTF-8');
 if (!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190) done(422,['ok'=>false,'message'=>'البريد الإلكتروني غير صحيح']);
 if (strlen($password)<12||strlen($password)>200) done(422,['ok'=>false,'message'=>'كلمة المرور يجب ألا تقل عن 12 حرفاً']);
 $v=randtoken(); $uid=bin2hex(random_bytes(16));
 $state=db(function (&$d) use ($email,$password,$v,$uid,$guestId,$displayName) {
  if (limited($d,'register',5,3600)) return 'limit';
  foreach ($d['users'] as $u) if (hash_equals($u['email'],$email)) return 'exists';
  $d['users'][$uid]=['uid'=>$uid,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'displayName'=>$displayName,'guestId'=>$guestId,'verified'=>false,'verify_hash'=>hash('sha256',$v),'verify_exp'=>time()+1800,'created'=>time()];
  return 'created';
 });
 if ($state==='limit') done(429,['ok'=>false,'message'=>'محاولات كثيرة، حاول لاحقاً']);
 if ($state==='exists') done(409,['ok'=>false,'message'=>'تعذر إنشاء حساب بهذا البريد']);
 $link=baseurl().'/api/auth.php?action=verify&token='.rawurlencode($v);
 if (!mailtoken($email,'Activate Baloot 450 account',$link)) done(503,['ok'=>false,'message'=>'تعذر إرسال رسالة التفعيل؛ راجع إعداد البريد']);
 done(201,['ok'=>true,'message'=>'أرسلنا رابط تفعيل الحساب إلى بريدك. بعد التفعيل سجّل الدخول بنفس المتصفح لاستعادة هوية ضيفك.']);
}
if ($action==='login') {
 $email=strtolower(trim((string)($in['email']??''))); $password=(string)($in['password']??''); $remember=($in['remember']??false)===true;
 $r=db(function (&$d) use ($email,$password,$remember,$guestId) {
  if (limited($d,'login',10,900)) return ['error'=>'limit'];
  $u=null; foreach ($d['users'] as $x) if (hash_equals($x['email'],$email)) {$u=$x;break;}
  if (!$u||!password_verify($password,$u['password'])) return ['error'=>'invalid'];
  if (empty($u['verified'])) return ['error'=>'verify'];
  if (password_needs_rehash($u['password'],PASSWORD_DEFAULT)) $d['users'][$u['uid']]['password']=password_hash($password,PASSWORD_DEFAULT);
  if (empty($u['guestId'])) $d['users'][$u['uid']]['guestId']=$guestId;
  $storedGuestId=(string)($d['users'][$u['uid']]['guestId']??$guestId);
  $t=randtoken(); $csrf=randtoken(); $expires=time()+($remember?2592000:43200);
  $d['sessions'][hash('sha256',$t)]=['uid'=>$u['uid'],'csrf'=>$csrf,'expires'=>$expires,'created'=>time()];
  return ['token'=>$t,'expires'=>$expires,'uid'=>$u['uid'],'email'=>$u['email'],'csrf'=>$csrf,'guestId'=>$storedGuestId,'displayName'=>(string)($u['displayName']??'')];
 });
 if (isset($r['error'])) {
  if ($r['error']==='limit') done(429,['ok'=>false,'message'=>'محاولات كثيرة، حاول لاحقاً']);
  if ($r['error']==='verify') done(403,['ok'=>false,'message'=>'فعّل بريدك الإلكتروني أولاً']);
  done(401,['ok'=>false,'message'=>'البريد أو كلمة المرور غير صحيحة']);
 }
 setauthcookie($r['token'],$r['expires']);
 setguestcookie($r['guestId']);
 done(200,['ok'=>true,'user'=>['id'=>$r['uid'],'email'=>$r['email'],'guestId'=>$r['guestId'],'displayName'=>$r['displayName']],'csrf'=>$r['csrf']]);
}
if ($action==='me') {
 $u=db(function (&$d) { return session_current($d); });
 if ($u && !empty($u['guestId'])) setguestcookie((string)$u['guestId']);
 done($u?200:401,['ok'=>(bool)$u,'user'=>$u?['id'=>$u['uid'],'email'=>$u['email'],'guestId'=>$u['guestId'],'displayName'=>$u['displayName']]:null,'csrf'=>$u['csrf']??null]);
}
if ($action==='logout'||$action==='logout_all') {
 $raw=(string)($_COOKIE['b450_session']??'');
 db(function (&$d) use ($raw,$action) {
  $key=hash('sha256',$raw); $s=$d['sessions'][$key]??null;
  if ($action==='logout_all'&&$s) { foreach ($d['sessions'] as $k=>$x) if ($x['uid']===$s['uid']) unset($d['sessions'][$k]); }
  else unset($d['sessions'][$key]);
  return true;
 });
 clearcookie(); done(200,['ok'=>true,'message'=>'تم تسجيل الخروج']);
}
done(404,['ok'=>false,'message'=>'العملية غير موجودة']);
