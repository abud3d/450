<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

require_once __DIR__ . '/../../app/Infrastructure/Database.php';
use Baloot\Infrastructure\Database;

function done(int $status,array $data): never { http_response_code($status); echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }
function body(): array {
 if ((int)($_SERVER['CONTENT_LENGTH']??0)>8192) done(413,['ok'=>false,'message'=>'حجم الطلب كبير']);
 $v=json_decode(file_get_contents('php://input')?:'{}',true);
 if (!is_array($v)) done(400,['ok'=>false,'message'=>'طلب غير صالح']);
 return $v;
}
function pdo(): PDO { try{return Database::connection();}catch(Throwable $e){error_log('Baloot auth DB: '.$e->getMessage());done(503,['ok'=>false,'message'=>'تعذر الاتصال بقاعدة البيانات']);} }
function origin_guard(): void {
 $expected=rtrim((string)getenv('APP_ORIGIN'),'/');
 if (!$expected) {$config=is_file('/home/arbim/baloot450-config.php')?require '/home/arbim/baloot450-config.php':[];$expected=rtrim((string)(is_array($config)?($config['origin']??''):'') ,'/');}
 $origin=rtrim((string)($_SERVER['HTTP_ORIGIN']??''),'/');
 if (!$expected||!$origin||!hash_equals($expected,$origin)) done(403,['ok'=>false,'message'=>'مصدر الطلب غير مسموح']);
}
function limited(PDO $db,string $scope,int $max,int $window): bool {
 $key=hash('sha256',$scope.'|'.($_SERVER['REMOTE_ADDR']??'unknown'));
 $sql='INSERT INTO baloot_api_limits (limit_key,window_started_at,hit_count) VALUES (?,UNIX_TIMESTAMP(),1)
 ON DUPLICATE KEY UPDATE hit_count=IF(window_started_at<=UNIX_TIMESTAMP()-?,1,hit_count+1),window_started_at=IF(window_started_at<=UNIX_TIMESTAMP()-?,UNIX_TIMESTAMP(),window_started_at)';
 $q=$db->prepare($sql);$q->execute([$key,$window,$window]);
 $q=$db->prepare('SELECT hit_count FROM baloot_api_limits WHERE limit_key=?');$q->execute([$key]);
 return (int)$q->fetchColumn()>$max;
}
function randtoken(): string { return bin2hex(random_bytes(32)); }
function setauthcookie(string $token,int $expires): void { setcookie('b450_session',$token,['expires'=>$expires,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); }
function clearcookie(): void { setcookie('b450_session','',['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); }
function setguestcookie(string $id): void { setcookie('baloot_guest',$id,['expires'=>time()+15552000,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); }
function currentguestid(): string {
 $id=$_COOKIE['baloot_guest']??'';
 if (!is_string($id)||!preg_match('/^[a-f0-9]{64}$/',$id)) $id=bin2hex(random_bytes(32));
 setguestcookie($id);return $id;
}
function user_payload(array $u): array {
 return ['id'=>$u['uid'],'email'=>$u['email'],'guestId'=>$u['guest_id'],'displayName'=>$u['display_name'],'avatar'=>$u['avatar']??'🃏','bio'=>$u['bio']??'',
 'stats'=>['points'=>(int)($u['points']??0),'matches'=>(int)($u['matches']??0),'wins'=>(int)($u['wins']??0),'losses'=>(int)($u['losses']??0),'draws'=>(int)($u['draws']??0)]];
}
function current_user(PDO $db): ?array {
 $raw=$_COOKIE['b450_session']??'';
 if (!is_string($raw)||!preg_match('/^[a-f0-9]{64}$/',$raw)) return null;
 $q=$db->prepare('SELECT u.*,s.csrf FROM baloot_user_sessions s JOIN baloot_users u ON u.uid=s.uid WHERE s.token_hash=? AND s.expires_at>UTC_TIMESTAMP() AND u.verified=1 LIMIT 1');
 $q->execute([hash('sha256',$raw)]);$u=$q->fetch();return $u?:null;
}
function mailtoken(string $email,string $subject,string $url): bool {
 $from=(string)getenv('MAIL_FROM');if (!$from||!filter_var($from,FILTER_VALIDATE_EMAIL)) return false;
 $msg="مرحباً،\nاستخدم الرابط الآمن التالي خلال 30 دقيقة:\n".$url."\nإذا لم تطلب هذه العملية فتجاهل الرسالة.";
 return @mail($email,'=?UTF-8?B?'.base64_encode($subject).'?=',$msg,"From: ".$from."\r\nContent-Type: text/plain; charset=UTF-8");
}
function baseurl(): string {
 $v=rtrim((string)getenv('APP_URL'),'/');if (!str_starts_with($v,'https://')) done(503,['ok'=>false,'message'=>'إعداد رابط HTTPS غير مكتمل']);return $v;
}
$action=(string)($_GET['action']??'');$method=(string)($_SERVER['REQUEST_METHOD']??'');
$db=pdo();

if ($action==='verify'&&$method==='GET') {
 $token=(string)($_GET['token']??'');if(!preg_match('/^[a-f0-9]{64}$/',$token))done(400,['ok'=>false,'message'=>'رابط غير صالح']);
 $q=$db->prepare('UPDATE baloot_users SET verified=1,verify_token_hash=NULL,verify_expires=NULL WHERE verify_token_hash=? AND verify_expires>=UTC_TIMESTAMP()');
 $q->execute([hash('sha256',$token)]);$ok=$q->rowCount()===1;done($ok?200:400,['ok'=>$ok,'message'=>$ok?'تم تفعيل الحساب':'الرابط منتهي أو غير صالح']);
}
if ($method!=='POST') done(405,['ok'=>false,'message'=>'طريقة الطلب غير مدعومة']);
origin_guard();$in=body();$guestId=currentguestid();

if ($action==='register') {
 $email=strtolower(trim((string)($in['email']??'')));$password=(string)($in['password']??'');$name=mb_substr(trim(strip_tags((string)($in['displayName']??''))),0,40,'UTF-8');
 if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)done(422,['ok'=>false,'message'=>'البريد الإلكتروني غير صحيح']);
 if(strlen($password)<12||strlen($password)>200)done(422,['ok'=>false,'message'=>'كلمة المرور يجب ألا تقل عن 12 حرفاً']);
 if(limited($db,'auth-register',5,3600))done(429,['ok'=>false,'message'=>'محاولات كثيرة، حاول لاحقاً']);
 $uid=bin2hex(random_bytes(16));$token=randtoken();
 try{$q=$db->prepare('INSERT INTO baloot_users (uid,email,password_hash,display_name,guest_id,verified,verify_token_hash,verify_expires) VALUES (?,?,?,?,?,0,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))');
  $q->execute([$uid,$email,password_hash($password,PASSWORD_DEFAULT),$name!==''?$name:'لاعب',$guestId,hash('sha256',$token)]);
 }catch(PDOException $e){if((string)$e->getCode()==='23000')done(409,['ok'=>false,'message'=>'يوجد حساب مسجل بهذا البريد أو هوية الضيف']);throw $e;}
 $link=baseurl().'/api/auth.php?action=verify&token='.rawurlencode($token);
 if(!mailtoken($email,'تفعيل حساب بلوت نت',$link)){$q=$db->prepare('DELETE FROM baloot_users WHERE uid=? AND verified=0');$q->execute([$uid]);done(503,['ok'=>false,'message'=>'تعذر إرسال رسالة التفعيل؛ راجع إعداد البريد ثم أعد المحاولة']);}
 done(201,['ok'=>true,'message'=>'أرسلنا رابط تفعيل الحساب إلى بريدك الإلكتروني.']);
}
if ($action==='login') {
 $email=strtolower(trim((string)($in['email']??'')));$password=(string)($in['password']??'');$remember=($in['remember']??false)===true;
 if(limited($db,'auth-login',10,900))done(429,['ok'=>false,'message'=>'محاولات كثيرة، حاول لاحقاً']);
 $q=$db->prepare('SELECT * FROM baloot_users WHERE email=? LIMIT 1');$q->execute([$email]);$u=$q->fetch();
 if(!$u||!password_verify($password,(string)$u['password_hash']))done(401,['ok'=>false,'message'=>'البريد أو كلمة المرور غير صحيحة']);
 if(!(int)$u['verified'])done(403,['ok'=>false,'message'=>'فعّل بريدك الإلكتروني أولاً']);
 if(password_needs_rehash((string)$u['password_hash'],PASSWORD_DEFAULT)){$q=$db->prepare('UPDATE baloot_users SET password_hash=? WHERE uid=?');$q->execute([password_hash($password,PASSWORD_DEFAULT),$u['uid']]);}
 $storedGuest=(string)$u['guest_id'];$token=randtoken();$csrf=randtoken();$expires=time()+($remember?2592000:43200);
 $q=$db->prepare('INSERT INTO baloot_user_sessions (token_hash,uid,csrf,expires_at) VALUES (?,?,?,FROM_UNIXTIME(?))');$q->execute([hash('sha256',$token),$u['uid'],$csrf,$expires]);
 setauthcookie($token,$expires);setguestcookie($storedGuest);$u['guest_id']=$storedGuest;
 done(200,['ok'=>true,'user'=>user_payload($u),'csrf'=>$csrf]);
}
if ($action==='me') {
 $u=current_user($db);if($u)setguestcookie((string)$u['guest_id']);
 done($u?200:401,['ok'=>(bool)$u,'user'=>$u?user_payload($u):null,'csrf'=>$u['csrf']??null]);
}
if ($action==='logout'||$action==='logout_all') {
 $raw=(string)($_COOKIE['b450_session']??'');$hash=hash('sha256',$raw);$u=current_user($db);
 if($action==='logout_all'&&$u){$q=$db->prepare('DELETE FROM baloot_user_sessions WHERE uid=?');$q->execute([$u['uid']]);}
 else{$q=$db->prepare('DELETE FROM baloot_user_sessions WHERE token_hash=?');$q->execute([$hash]);}
 clearcookie();done(200,['ok'=>true,'message'=>'تم تسجيل الخروج']);
}
if ($action==='forgot') {
 $email=strtolower(trim((string)($in['email']??'')));if(limited($db,'auth-forgot',5,3600))done(429,['ok'=>false,'message'=>'طلبات كثيرة؛ حاول لاحقاً']);
 $q=$db->prepare('SELECT uid FROM baloot_users WHERE email=? AND verified=1 LIMIT 1');$q->execute([$email]);$uid=$q->fetchColumn();
 if($uid){$token=randtoken();$q=$db->prepare('INSERT INTO baloot_password_resets (token_hash,uid,expires_at) VALUES (?,?,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 30 MINUTE))');$q->execute([hash('sha256',$token),$uid]);
  $link=baseurl().'/?reset='.rawurlencode($token);
  if(!mailtoken($email,'إعادة تعيين كلمة مرور بلوت نت',$link))done(503,['ok'=>false,'message'=>'تعذر إرسال رسالة الاستعادة؛ تحقق من إعداد البريد على الخادم']);
 }
 done(200,['ok'=>true,'message'=>'إذا كان البريد مسجلاً ومفعّلاً فستصلك رسالة برابط إعادة التعيين.']);
}
if ($action==='reset') {
 $token=(string)($in['token']??'');$password=(string)($in['password']??'');
 if(strlen($password)<12||strlen($password)>200)done(422,['ok'=>false,'message'=>'كلمة المرور يجب ألا تقل عن 12 حرفاً']);
 if(!preg_match('/^[a-f0-9]{64}$/',$token))done(400,['ok'=>false,'message'=>'رابط الاستعادة غير صالح']);
 $q=$db->prepare('SELECT uid FROM baloot_password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>=UTC_TIMESTAMP() LIMIT 1');$q->execute([hash('sha256',$token)]);$uid=$q->fetchColumn();
 if(!$uid)done(400,['ok'=>false,'message'=>'رابط الاستعادة منتهي أو غير صالح؛ اطلب رابطاً جديداً']);
 Database::transaction(function(PDO $pdo)use($uid,$token,$password){
  $q=$pdo->prepare('SELECT uid FROM baloot_password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at>=UTC_TIMESTAMP() FOR UPDATE');$q->execute([hash('sha256',$token)]);$found=$q->fetchColumn();if(!$found)throw new RuntimeException('reset token used');
  $q=$pdo->prepare('UPDATE baloot_users SET password_hash=? WHERE uid=?');$q->execute([password_hash($password,PASSWORD_DEFAULT),$uid]);
  $q=$pdo->prepare('UPDATE baloot_password_resets SET used_at=UTC_TIMESTAMP() WHERE token_hash=?');$q->execute([hash('sha256',$token)]);
  $q=$pdo->prepare('DELETE FROM baloot_user_sessions WHERE uid=?');$q->execute([$uid]);
 });
 done(200,['ok'=>true,'message'=>'تم تغيير كلمة المرور. سجّل الدخول بكلمتك الجديدة.']);
}
if ($action==='profile') {
 $u=current_user($db);if(!$u)done(401,['ok'=>false,'message'=>'سجّل الدخول أولاً']);
 $name=mb_substr(trim(strip_tags((string)($in['displayName']??''))),0,40,'UTF-8');$avatar=(string)($in['avatar']??'🃏');$bio=mb_substr(trim(strip_tags((string)($in['bio']??''))),0,120,'UTF-8');
 if(mb_strlen($name,'UTF-8')<2)done(422,['ok'=>false,'message'=>'اسم اللاعب يجب أن يكون حرفين على الأقل']);
 if(!in_array($avatar,['🃏','♠','♥','♦','♣','😀','😎','🤠','👑','🐪','🦅','⚽','🏆'],true))done(422,['ok'=>false,'message'=>'الصورة الرمزية غير معتمدة']);
 $q=$db->prepare('UPDATE baloot_users SET display_name=?,avatar=?,bio=? WHERE uid=?');$q->execute([$name,$avatar,$bio,$u['uid']]);
 $q=$db->prepare('SELECT * FROM baloot_users WHERE uid=?');$q->execute([$u['uid']]);done(200,['ok'=>true,'user'=>user_payload($q->fetch())]);
}
done(404,['ok'=>false,'message'=>'العملية غير موجودة']);
