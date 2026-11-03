<?php
declare(strict_types=1);

/*
 * Baloot 450 account API — PHP 8.1+
 * Set BALOUT_DATA_DIR to a writable directory OUTSIDE the public web root.
 * Set APP_URL (https://your-domain.example), APP_ORIGIN and BALOUT_MAIL_FROM.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: DENY');

const SESSION_COOKIE = 'baloot_session';
const SESSION_SHORT_TTL = 43200;
const SESSION_REMEMBER_TTL = 2592000;
const VERIFY_TTL = 1800;

function respond(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function request_json(): array {
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) respond(413, ['ok'=>false,'error'=>'حجم الطلب كبير']);
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) respond(400, ['ok'=>false,'error'=>'طلب غير صالح']);
    return $data;
}
function store_path(): string {
    $dir = getenv('BALOUT_DATA_DIR') ?: dirname(__DIR__, 2) . '/private-data';
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) respond(500, ['ok'=>false,'error'=>'تعذر تهيئة التخزين']);
    $real = realpath($dir);
    if (!$real || !is_writable($real)) respond(500, ['ok'=>false,'error'=>'مجلد التخزين غير قابل للكتابة']);
    return $real . '/accounts.json';
}
function with_store(callable $callback): mixed {
    $path = store_path();
    $fp = @fopen($path, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) respond(503, ['ok'=>false,'error'=>'التخزين مشغول، حاول مجدداً']);
    try {
        rewind($fp);
        $raw = stream_get_contents($fp);
        $db = $raw ? json_decode($raw, true) : null;
        if (!is_array($db)) $db = ['users'=>[], 'sessions'=>[], 'limits'=>[]];
        $result = $callback($db);
        rewind($fp);
        if (!ftruncate($fp, 0)) throw new RuntimeException('truncate failed');
        $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || fwrite($fp, $json) === false || !fflush($fp)) throw new RuntimeException('write failed');
        return $result;
    } catch (Throwable $e) {
        error_log('Baloot account store error: ' . $e->getMessage());
        respond(500, ['ok'=>false,'error'=>'تعذر إتمام العملية']);
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}
function client_ip_key(): string {
    return hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown') . (getenv('BALOUT_RATE_SECRET') ?: __FILE__));
}
function rate_limit(array &$db, string $bucket, int $max, int $window): bool {
    $now = time();
    $key = hash('sha256', $bucket . '|' . client_ip_key());
    $entry = $db['limits'][$key] ?? ['start'=>$now,'count'=>0];
    if ($now - (int)$entry['start'] >= $window) $entry = ['start'=>$now,'count'=>0];
    $entry['count']++;
    $db['limits'][$key] = $entry;
    return $entry['count'] <= $max;
}
function require_same_origin(): void {
    $expected = getenv('APP_ORIGIN');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($expected && (!hash_equals(rtrim($expected, '/'), rtrim($origin, '/')))) {
        respond(403, ['ok'=>false,'error'=>'مصدر الطلب غير مسموح']);
    }
}
function cookie_set(string $token, int $expires): void {
    setcookie(SESSION_COOKIE, $token, [
        'expires'=>$expires, 'path'=>'/', 'secure'=>true, 'httponly'=>true,
        'samesite'=>'Lax'
    ]);
}
function cookie_clear(): void {
    setcookie(SESSION_COOKIE, '', ['expires'=>1,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
}
function current_user(array &$db): ?array {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    if (!is_string($token) || strlen($token) < 40) return null;
    $hash = hash('sha256', $token);
    $session = $db['sessions'][$hash] ?? null;
    if (!$session || (int)$session['expires'] < time()) {
        unset($db['sessions'][$hash]);
        return null;
    }
    $user = $db['users'][$session['user_id']] ?? null;
    if (!$user || empty($user['verified'])) return null;
    return ['id'=>$user['id'], 'email'=>$user['email'], 'created_at'=>$user['created_at']];
}
function send_verify_email(string $email, string $token): bool {
    $base = rtrim((string)getenv('APP_URL'), '/');
    $from = (string)getenv('BALOUT_MAIL_FROM');
    if (!$base || !$from || !filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    $link = $base . '/api/?action=verify&token=' . rawurlencode($token);
    $subject = 'تفعيل حساب بلوت';
    $body = "مرحباً،\n\nفعّل حسابك عبر الرابط التالي (صالح لمدة 30 دقيقة):\n{$link}\n\nإذا لم تطلب إنشاء الحساب فتجاهل الرسالة.";
    $headers = "From: {$from}\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, $headers);
}
$action = (string)($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($action === 'verify' && $method === 'GET') {
    $token = (string)($_GET['token'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) respond(400, ['ok'=>false,'error'=>'رابط التفعيل غير صالح']);
    $done = with_store(function (&$db) use ($token) {
        $hash = hash('sha256', $token);
        foreach ($db['users'] as &$user) {
            if (!empty($user['verify_hash']) && hash_equals($user['verify_hash'], $hash) && (int)$user['verify_expires'] >= time()) {
                $user['verified'] = true;
                unset($user['verify_hash'], $user['verify_expires']);
                return true;
            }
        }
        return false;
    });
    respond($done ? 200 : 400, ['ok'=>$done,'message'=>$done?'تم تفعيل الحساب. يمكنك تسجيل الدخول.':'انتهت صلاحية الرابط أو أنه غير صالح']);
}

if ($method !== 'POST') respond(405, ['ok'=>false,'error'=>'طريقة الطلب غير مدعومة']);
require_same_origin();
$input = request_json();

if ($action === 'register') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) respond(422, ['ok'=>false,'error'=>'أدخل بريداً إلكترونياً صحيحاً']);
    if (strlen($password) < 12 || strlen($password) > 200) respond(422, ['ok'=>false,'error'=>'كلمة المرور يجب ألا تقل عن 12 حرفاً']);
    $token = bin2hex(random_bytes(32));
    $userId = bin2hex(random_bytes(16));
    $created = with_store(function (&$db) use ($email,$password,$token,$userId) {
        if (!rate_limit($db, 'register', 5, 3600)) return 'limited';
        foreach ($db['users'] as $u) if (hash_equals((string)$u['email'], $email)) return 'exists';
        $db['users'][$userId] = [
            'id'=>$userId, 'email'=>$email,
            'password_hash'=>password_hash($password, PASSWORD_DEFAULT),
            'verified'=>false, 'verify_hash'=>hash('sha256',$token),
            'verify_expires'=>time()+VERIFY_TTL, 'created_at'=>gmdate('c')
        ];
        return 'created';
    });
    if ($created === 'limited') respond(429, ['ok'=>false,'error'=>'محاولات كثيرة، حاول لاحقاً']);
    if ($created === 'exists') respond(409, ['ok'=>false,'error'=>'تعذر إنشاء الحساب بهذا البريد']);
    if (!send_verify_email($email, $token)) respond(503, ['ok'=>false,'error'=>'تم حفظ طلب التسجيل لكن تعذر إرسال رسالة التفعيل. راجع إعداد البريد قبل إعادة المحاولة.']);
    respond(201, ['ok'=>true,'message'=>'أرسلنا رابط التفعيل إلى بريدك.']);
}

if ($action === 'login') {
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    $remember = ($input['remember'] ?? false) === true;
    $result = with_store(function (&$db) use ($email,$password,$remember) {
        if (!rate_limit($db, 'login', 10, 900)) return ['error'=>'limited'];
        $user = null;
        foreach ($db['users'] as $u) if (hash_equals((string)$u['email'], $email)) { $user = $u; break; }
        if (!$user || !password_verify($password, (string)$user['password_hash'])) return ['error'=>'invalid'];
        if (empty($user['verified'])) return ['error'=>'unverified'];
        if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
            $db['users'][$user['id']]['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $token = bin2hex(random_bytes(32));
        $expires = time() + ($remember ? SESSION_REMEMBER_TTL : SESSION_SHORT_TTL);
        $db['sessions'][hash('sha256',$token)] = ['user_id'=>$user['id'],'expires'=>$expires,'created_at'=>time()];
        return ['token'=>$token,'expires'=>$expires,'user'=>['id'=>$user['id'],'email'=>$user['email']]];
    });
    if (isset($result['error'])) {
        if ($result['error']==='limited') respond(429,['ok'=>false,'error'=>'محاولات كثيرة، حاول بعد قليل']);
        if ($result['error']==='unverified') respond(403,['ok'=>false,'error'=>'فعّل بريدك الإلكتروني أولاً']);
        respond(401,['ok'=>false,'error'=>'البريد أو كلمة المرور غير صحيحة']);
    }
    cookie_set($result['token'], $result['expires']);
    unset($result['token'],$result['expires']);
    respond(200,['ok'=>true,'user'=>$result['user']]);
}

if ($action === 'me') {
    $user = with_store(function (&$db) { return current_user($db); });
    respond($user ? 200 : 401, ['ok'=>(bool)$user,'user'=>$user]);
}
if ($action === 'logout' || $action === 'logout_all') {
    $token = $_COOKIE[SESSION_COOKIE] ?? '';
    with_store(function (&$db) use ($token,$action) {
        $hash = is_string($token) ? hash('sha256',$token) : '';
        $session = $db['sessions'][$hash] ?? null;
        if ($action === 'logout_all' && $session) {
            foreach ($db['sessions'] as $key=>$s) if ($s['user_id']===$session['user_id']) unset($db['sessions'][$key]);
        } else unset($db['sessions'][$hash]);
        return true;
    });
    cookie_clear();
    respond(200,['ok'=>true,'message'=>'تم تسجيل الخروج']);
}
respond(404, ['ok'=>false,'error'=>'العملية غير موجودة']);
