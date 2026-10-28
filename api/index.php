<?php
declare(strict_types=1);
require __DIR__.'/config.php';
$path = api_path();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = ($method==='GET'||$method==='HEAD') ? [] : parse_request_body();

try {
if ($path==='/api/health' && $method==='GET') {
  db()->query('SELECT 1');
  json_response(['ok'=>true,'database'=>true,'driver'=>db()->getAttribute(PDO::ATTR_DRIVER_NAME)]);
}

if ($path==='/api/auth/send-code' && $method==='POST') {
  rate_limit('send-code',5,60);
  $email = norm_email($body['email']??'');
  $purpose = ($body['purpose']??'')==='reset' ? 'reset' : 'signup';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(['error'=>'ایمیل معتبر وارد کنید.'],400);
  $st = db()->prepare('SELECT 1 FROM users WHERE email=?');
  $st->execute([$email]);
  $exists = (bool)$st->fetchColumn();
  if ($purpose === 'reset' && !$exists) json_response(['error'=>'حسابی با این ایمیل پیدا نشد.'],404);
  if ($purpose === 'signup' && $exists) json_response(['error'=>'این ایمیل قبلاً ثبت‌نام کرده است.'],409);
  $st = db()->prepare('SELECT created_at FROM email_verifications WHERE email=? AND purpose=? ORDER BY created_at DESC LIMIT 1');
  $st->execute([$email,$purpose]);
  $recent = $st->fetch();
  if ($recent) {
    $ts = strtotime($recent['created_at']);
    if ($ts && time() - $ts < 60) json_response(['error'=>'لطفاً قبل از ارسال کد جدید، ۶۰ ثانیه صبر کنید.'],429);
  }
  $code = str_pad((string)random_int(10000,99999), 5, '0', STR_PAD_LEFT);
  $pdo = db();
  $pdo->prepare('DELETE FROM email_verifications WHERE email=? AND purpose=?')->execute([$email,$purpose]);
  $id = new_id();
  if (is_sqlite()) {
    $expires = date('Y-m-d H:i:s', time() + 600);
    $pdo->prepare('INSERT INTO email_verifications(id,email,purpose,code_hash,expires_at) VALUES(?,?,?,?,?)')
        ->execute([$id,$email,$purpose,hash_code($code),$expires]);
  } else {
    $pdo->prepare("INSERT INTO email_verifications(email,purpose,code_hash,expires_at) VALUES(?,?,?,now()+interval '10 minutes')")
        ->execute([$email,$purpose,hash_code($code)]);
  }
  try {
    send_verification_email($email,$code,$purpose);
  } catch (Throwable $e) {
    $pdo->prepare('DELETE FROM email_verifications WHERE email=? AND purpose=?')->execute([$email,$purpose]);
    json_response([
      'error' => $e->getMessage()==='EMAIL_NOT_CONFIGURED'
        ? 'ارسال ایمیل تنظیم نشده است. RESEND_API_KEY و MAIL_FROM را در .env تنظیم کنید.'
        : 'ارسال ایمیل انجام نشد. تنظیمات Resend را بررسی کنید.'
    ], $e->getMessage()==='EMAIL_NOT_CONFIGURED' ? 503 : 502);
  }
  json_response(['ok'=>true,'message'=>$purpose==='signup' ? 'کد تایید به ایمیل شما ارسال شد.' : 'کد بازیابی به ایمیل شما ارسال شد.']);
}

if ($path==='/api/auth/verify-code' && $method==='POST') {
  $email = norm_email($body['email']??'');
  $code = trim((string)($body['code']??''));
  $purpose = ($body['purpose']??'')==='reset' ? 'reset' : 'signup';
  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{5}$/', $code)) {
    json_response(['error'=>'کد ۵ رقمی را وارد کنید.'],400);
  }
  if (is_sqlite()) {
    $st = db()->prepare("SELECT * FROM email_verifications WHERE email=? AND purpose=? AND verified_at IS NULL AND expires_at > datetime('now') ORDER BY created_at DESC LIMIT 1");
  } else {
    $st = db()->prepare("SELECT * FROM email_verifications WHERE email=? AND purpose=? AND verified_at IS NULL AND expires_at>now() ORDER BY created_at DESC LIMIT 1");
  }
  $st->execute([$email,$purpose]);
  $r = $st->fetch();
  if (!$r) json_response(['error'=>'کد منقضی شده یا وجود ندارد. کد جدید بگیرید.'],400);
  if ((int)$r['attempts'] >= 5) json_response(['error'=>'تعداد تلاش‌ها تمام شد. کد جدید بگیرید.'],429);
  if (!hash_equals($r['code_hash'], hash_code($code))) {
    db()->prepare('UPDATE email_verifications SET attempts=attempts+1 WHERE id=?')->execute([$r['id']]);
    json_response(['error'=>'کد تایید اشتباه است.'],400);
  }
  if (is_sqlite()) {
    db()->prepare("UPDATE email_verifications SET verified_at=datetime('now') WHERE id=?")->execute([$r['id']]);
  } else {
    db()->prepare('UPDATE email_verifications SET verified_at=now() WHERE id=?')->execute([$r['id']]);
  }
  json_response(['ok'=>true,'token'=>issue_verification_token($email,$purpose)]);
}

if ($path==='/api/auth/register' && $method==='POST') {
  $name = trim((string)($body['name']??''));
  $email = norm_email($body['email']??'');
  $phone = norm_phone($body['phone']??'');
  $password = (string)($body['password']??'');
  if (strlen($name) < 2 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
    json_response(['error'=>'نام، ایمیل معتبر و رمز حداقل ۸ کاراکتری الزامی است.'],400);
  }
  $st = db()->prepare('SELECT 1 FROM users WHERE email=?');
  $st->execute([$email]);
  if ($st->fetchColumn()) {
    json_response(['error'=>'این ایمیل قبلاً ثبت‌نام شده است. وارد حساب شوید یا رمز عبور را بازیابی کنید.'],409);
  }
  $admin = norm_email(envv('ADMIN_EMAIL',''));
  $role = ($admin !== '' && $email === $admin) ? 'admin' : 'user';
  start_secure_session();
  try {
    if (is_sqlite()) {
      $id = new_id();
      $now = date('Y-m-d H:i:s');
      db()->prepare('INSERT INTO users(id,name,email,phone,password_hash,role,created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id,$name,$email,$phone!==''?$phone:null,password_hash($password,PASSWORD_DEFAULT),$role,$now]);
      $st = db()->prepare('SELECT id,email,name,phone,role,created_at FROM users WHERE id=?');
      $st->execute([$id]);
      $u = $st->fetch();
    } else {
      $st = db()->prepare('INSERT INTO users(name,email,phone,password_hash,role) VALUES(?,?,?,?,?) RETURNING id,email,name,phone,role,created_at');
      $st->execute([$name,$email,$phone!==''?$phone:null,password_hash($password,PASSWORD_DEFAULT),$role]);
      $u = $st->fetch();
    }
    if (!$u) json_response(['error'=>'حساب ساخته نشد؛ پاسخ پایگاه‌داده نامعتبر بود.'],500);
  } catch (PDOException $e) {
    $code = (string)$e->getCode();
    if ($code === '23505' || str_contains($e->getMessage(), 'UNIQUE') || str_contains($e->getMessage(), 'unique')) {
      json_response(['error'=>'این ایمیل قبلاً ثبت‌نام شده است. وارد حساب شوید یا رمز عبور را بازیابی کنید.'],409);
    }
    error_log('REGISTER DB ERROR: '.$e->getMessage());
    $msg = 'خطا در ثبت حساب در پایگاه‌داده.';
    if (strtolower((string)envv('APP_DEBUG','false')) === 'true') {
      $msg .= ' '.$e->getMessage();
    } else {
      $msg .= ' تنظیمات دیتابیس را بررسی کنید.';
    }
    json_response(['error'=>$msg],500);
  }
  $_SESSION['user_id'] = $u['id'];
  session_regenerate_id(true);
  json_response(['ok'=>true,'user'=>public_user($u)],201);
}

if ($path==='/api/auth/reset-password' && $method==='POST') {
  $vt = (string)($body['resetToken']??'');
  $new = (string)($body['newPassword']??'');
  if (strlen($new) < 8 || $vt === '') json_response(['error'=>'رمز جدید باید حداقل ۸ کاراکتر باشد.'],400);
  start_secure_session();
  $v = $_SESSION['verified_tokens']['reset'] ?? null;
  if (!$v || !hash_equals($v['token'], $vt) || $v['expires'] < time()) {
    json_response(['error'=>'کد بازیابی منقضی شده است.'],400);
  }
  $email = $v['email'];
  unset($_SESSION['verified_tokens']['reset']);
  if (is_sqlite()) {
    db()->prepare('UPDATE users SET password_hash=? WHERE email=?')->execute([password_hash($new,PASSWORD_DEFAULT),$email]);
    $st = db()->prepare('SELECT id,email,name,phone,role,created_at FROM users WHERE email=?');
    $st->execute([$email]);
    $u = $st->fetch();
  } else {
    $st = db()->prepare('UPDATE users SET password_hash=? WHERE email=? RETURNING id,email,name,phone,role,created_at');
    $st->execute([password_hash($new,PASSWORD_DEFAULT),$email]);
    $u = $st->fetch();
  }
  if (!$u) json_response(['error'=>'حساب پیدا نشد.'],400);
  db()->prepare("DELETE FROM email_verifications WHERE email=? AND purpose='reset'")->execute([$email]);
  $_SESSION['user_id'] = $u['id'];
  session_regenerate_id(true);
  json_response(['ok'=>true,'user'=>public_user($u),'message'=>'رمز عبور با موفقیت تغییر کرد.']);
}

if ($path==='/api/auth/login' && $method==='POST') {
  rate_limit('login',20,600);
  $identifier = trim((string)($body['email'] ?? $body['identifier'] ?? ''));
  $password = (string)($body['password']??'');
  if ($identifier === '' || $password === '') {
    json_response(['error'=>'ایمیل یا شماره موبایل و رمز عبور را وارد کنید.'],400);
  }

  // Login accepts either the account email or the mobile number.
  // Prefer email lookup when the input looks like an email (contains @),
  // otherwise treat it as a phone. Email is matched case-insensitively so
  // legacy mixed-case rows still work. Phone is normalized and also tried
  // against common legacy formats (+98… / 98… / without leading 0).
  $email = norm_email($identifier);
  $phone = norm_phone($identifier);
  $looksLikeEmail = str_contains($identifier, '@');

  if ($looksLikeEmail) {
    $st = db()->prepare('SELECT * FROM users WHERE LOWER(email)=? LIMIT 1');
    $st->execute([$email]);
  } else {
    $phoneVariants = array_values(array_unique(array_filter([
      $phone,
      $phone !== '' ? preg_replace('/^0/', '+98', $phone) : null,
      $phone !== '' ? preg_replace('/^0/', '98', $phone) : null,
      $phone !== '' ? ltrim($phone, '0') : null,
    ], static fn($v) => $v !== null && $v !== '')));
    if (!$phoneVariants) {
      json_response(['error'=>'ایمیل/موبایل یا رمز عبور اشتباه است.'],401);
    }
    $ph = implode(',', array_fill(0, count($phoneVariants), '?'));
    $st = db()->prepare("SELECT * FROM users WHERE phone IN ($ph) LIMIT 1");
    $st->execute($phoneVariants);
  }
  $u = $st->fetch();

  if (!$u || empty($u['password_hash']) || !password_verify($password, (string)$u['password_hash'])) {
    json_response(['error'=>'ایمیل/موبایل یا رمز عبور اشتباه است.'],401);
  }

  // A successful login starts a fresh authenticated session and clears the
  // failed-login counter for this IP, so old attempts cannot block the user.
  clear_rate_limit('login');
  start_secure_session();
  session_regenerate_id(true);
  $_SESSION['user_id'] = $u['id'];
  $_SESSION['login_at'] = time();
  json_response(['ok'=>true,'user'=>public_user($u)]);
}

if ($path==='/api/auth/logout' && $method==='POST') {
  start_secure_session();
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    setcookie(session_name(), '', time()-42000, [
      'path' => '/',
      'secure' => is_https(),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
  session_destroy();
  json_response(['ok'=>true]);
}

if ($path==='/api/auth/me' && $method==='GET') {
  start_secure_session();
  if (empty($_SESSION['user_id'])) {
    json_response(['user'=>null]);
  }
  $st = db()->prepare('SELECT id,email,name,phone,role,created_at FROM users WHERE id=?');
  $st->execute([$_SESSION['user_id']]);
  $u = $st->fetch();
  if (!$u) {
    $_SESSION = [];
    session_destroy();
    json_response(['user'=>null]);
  }
  json_response(['user'=>public_user($u)]);
}

if ($path==='/api/auth/change-password' && $method==='POST') {
  $u = require_auth();
  $cur = (string)($body['currentPassword']??'');
  $new = (string)($body['newPassword']??'');
  if (!$cur || strlen($new) < 8) json_response(['error'=>'رمز جدید باید حداقل ۸ کاراکتر باشد.'],400);
  $st = db()->prepare('SELECT password_hash FROM users WHERE id=?');
  $st->execute([$u['id']]);
  $r = $st->fetch();
  if (!$r || !password_verify($cur, $r['password_hash'])) {
    json_response(['error'=>'رمز فعلی اشتباه است.'],401);
  }
  db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($new,PASSWORD_DEFAULT),$u['id']]);
  json_response(['ok'=>true,'message'=>'رمز عبور با موفقیت تغییر کرد.']);
}

if ($path==='/api/contact' && $method==='POST') {
  rate_limit('contact',8,60);
  $name = trim((string)($body['name']??''));
  $email = trim((string)($body['email']??''));
  $message = trim((string)($body['message']??''));
  if (strlen($name)<2 || strlen($name)>100 || !filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($message)<5 || strlen($message)>5000) {
    json_response(['error'=>'همه فیلدها الزامی هستند.'],400);
  }
  if (is_sqlite()) {
    $id = new_id();
    $now = date('Y-m-d H:i:s');
    db()->prepare('INSERT INTO contact_messages(id,name,email,message,created_at) VALUES(?,?,?,?,?)')
      ->execute([$id,$name,$email,$message,$now]);
    json_response(['message'=>['id'=>$id,'created_at'=>$now]],201);
  } else {
    $st = db()->prepare('INSERT INTO contact_messages(name,email,message) VALUES(?,?,?) RETURNING id,created_at');
    $st->execute([$name,$email,$message]);
    json_response(['message'=>$st->fetch()],201);
  }
}

if ($path==='/api/orders' && $method==='POST') {
  $u = require_auth();
  $description = trim((string)($_POST['description']??''));
  if (strlen($description) < 8) json_response(['error'=>'توضیح پروژه کافی نیست.'],400);
  $features = safe_json($_POST['features']??'[]');
  $items = safe_json($_POST['items']??'[]');
  $gateway = (string)($_POST['gateway'] ?? 'manual');
  if ($gateway === 'zarinpal' || $gateway === 'idpay') {
    json_response(['error'=>'این درگاه فعلاً بزودی فعال می‌شود.'],400);
  }

  $stored = null; $orig = null; $mime = null;
  if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['attachment']['error'] !== UPLOAD_ERR_OK || $_FILES['attachment']['size'] > 8*1024*1024) {
      json_response(['error'=>'فایل نامعتبر یا بزرگ‌تر از ۸MB است.'],400);
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $_FILES['attachment']['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg','image/png','image/webp','application/pdf','application/zip','text/plain'];
    if ($gateway === 'card') $allowed = ['image/jpeg','image/png','image/webp','application/pdf'];
    if (!in_array($mime, $allowed, true)) json_response(['error'=>'نوع فایل مجاز نیست.'],400);
    $ext = match($mime) {
      'image/jpeg'=>'.jpg','image/png'=>'.png','image/webp'=>'.webp',
      'application/pdf'=>'.pdf','application/zip'=>'.zip','text/plain'=>'.txt', default=>''
    };
    $dir = __DIR__.'/../uploads';
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $stored = bin2hex(random_bytes(20)).$ext;
    if (!move_uploaded_file($_FILES['attachment']['tmp_name'], $dir.'/'.$stored)) {
      json_response(['error'=>'آپلود فایل ناموفق بود.'],500);
    }
    $orig = basename((string)$_FILES['attachment']['name']);
  }


  try {
    $packageName = $_POST['packageName'] ?? null;
    $projectType = $_POST['projectType'] ?? null;
    $budget = $_POST['budget'] ?? null;
    $deadline = $_POST['deadline'] ?? null;
    $contactName = $_POST['contactName'] ?? $u['name'];
    $contactPhone = $_POST['contactPhone'] ?? $u['phone'];
    $amount = isset($_POST['amount']) && $_POST['amount'] !== '' ? (int)$_POST['amount'] : null;
    $featuresJson = json_encode($features, JSON_UNESCAPED_UNICODE);
    $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
    $paymentStatus = $gateway === 'card' ? 'pending' : 'unpaid';

    if (is_sqlite()) {
      $id = new_id();
      $now = date('Y-m-d H:i:s');
      db()->prepare('INSERT INTO orders(id,user_id,package_name,project_type,description,budget,deadline,features,contact_name,contact_phone,amount,items,attachment_original_name,attachment_path,attachment_mime,payment_status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$id,$u['id'],$packageName,$projectType,$description,$budget,$deadline,$featuresJson,$contactName,$contactPhone,$amount,$itemsJson,$orig,$stored,$mime,$paymentStatus,$now,$now]);
      if ($gateway === 'card') {
        db()->prepare('INSERT INTO payments(id,order_id,provider,ref_id,status,amount,created_at) VALUES(?,?,?,?,?,?,?)')
          ->execute([new_id(),$id,'card_to_card',null,'pending',$amount,$now]);
      }
      $st = db()->prepare('SELECT id,status,payment_status,created_at FROM orders WHERE id=?');
      $st->execute([$id]);
      $order = $st->fetch();
    } else {
      $st = db()->prepare('INSERT INTO orders(user_id,package_name,project_type,description,budget,deadline,features,contact_name,contact_phone,amount,items,attachment_original_name,attachment_path,attachment_mime,payment_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?) RETURNING id,status,payment_status,created_at');
      $st->execute([$u['id'],$packageName,$projectType,$description,$budget,$deadline,$featuresJson,$contactName,$contactPhone,$amount,$itemsJson,$orig,$stored,$mime,$paymentStatus]);
      $order = $st->fetch();
      if ($gateway === 'card') {
        db()->prepare('INSERT INTO payments(order_id,provider,ref_id,status,amount) VALUES(?,?,?,?,?)')
          ->execute([$order['id'],'card_to_card',null,'pending',$amount]);
      }
    }

    $telegramSent = false;
    if ($gateway === 'card' && $stored) {
      $itemNames = array_values(array_filter(array_map(
        fn($x) => is_array($x) ? (string)($x['title'] ?? '') : (string)$x,
        $items
      )));
      $caption = "🧾 رسید پرداخت کارت‌به‌کارت\n"
        . "سفارش: " . $order['id'] . "\n"
        . "نام: " . $u['name'] . "\n"
        . "ایمیل: " . $u['email'] . "\n"
        . "موبایل: " . ($u['phone'] ?? '—') . "\n"
        . "مبلغ: " . number_format((int)($amount ?? 0)) . " تومان\n"
        . "محصولات: " . ($itemNames ? implode('، ', $itemNames) : '—');
      $telegramSent = send_telegram_receipt(__DIR__.'/../uploads/'.$stored, $caption);
    }

    json_response(['order'=>$order,'telegram_sent'=>$telegramSent],201);
  } catch (Throwable $e) {
    if ($stored) @unlink(__DIR__.'/../uploads/'.$stored);
    throw $e;
  }
}

if ($path==='/api/orders' && $method==='GET') {
  $u = require_auth();
  $st = db()->prepare('SELECT id,package_name,project_type,description,budget,deadline,features,status,payment_status,amount,items,attachment_original_name,created_at,updated_at FROM orders WHERE user_id=? ORDER BY created_at DESC');
  $st->execute([$u['id']]);
  json_response(['orders'=>$st->fetchAll()]);
}

if ($path==='/api/admin/orders' && $method==='GET') {
  $u = require_admin();
  $r = db()->query('SELECT o.*,u.name user_name,u.email user_email,u.phone user_phone FROM orders o JOIN users u ON u.id=o.user_id ORDER BY o.created_at DESC')->fetchAll();
  json_response(['orders'=>$r]);
}

if (preg_match('#^/api/admin/orders/([0-9a-f-]+)$#',$path,$m) && $method==='PATCH') {
  require_admin();
  $allowed = ['pending','in_progress','completed','cancelled'];
  $status = $body['status'] ?? '';
  if (!in_array($status, $allowed, true)) json_response(['error'=>'وضعیت نامعتبر است.'],400);
  if (is_sqlite()) {
    db()->prepare("UPDATE orders SET status=?,updated_at=datetime('now') WHERE id=?")->execute([$status,$m[1]]);
    $st = db()->prepare('SELECT * FROM orders WHERE id=?');
    $st->execute([$m[1]]);
    $r = $st->fetch();
  } else {
    $st = db()->prepare('UPDATE orders SET status=?,updated_at=now() WHERE id=? RETURNING *');
    $st->execute([$status,$m[1]]);
    $r = $st->fetch();
  }
  if (!$r) json_response(['error'=>'سفارش پیدا نشد.'],404);
  json_response(['order'=>$r]);
}

if (preg_match('#^/api/admin/orders/([0-9a-f-]+)/attachment$#',$path,$m) && $method==='GET') {
  require_admin();
  $st = db()->prepare('SELECT attachment_path,attachment_original_name,attachment_mime FROM orders WHERE id=?');
  $st->execute([$m[1]]);
  $r = $st->fetch();
  if (!$r || !$r['attachment_path']) json_response(['error'=>'فایلی وجود ندارد'],404);
  $p = __DIR__.'/../uploads/'.$r['attachment_path'];
  if (!is_file($p)) json_response(['error'=>'فایل پیدا نشد'],404);
  header('Content-Type: '.($r['attachment_mime']?:'application/octet-stream'));
  header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($r['attachment_original_name']?:'attachment')).'"');
  header('Content-Length: '.filesize($p));
  readfile($p);
  exit;
}

if ($path==='/api/admin/contacts' && $method==='GET') {
  require_admin();
  json_response(['messages'=>db()->query('SELECT * FROM contact_messages ORDER BY created_at DESC')->fetchAll()]);
}

if ($path==='/api/admin/stats' && $method==='GET') {
  require_admin();
  if (is_sqlite()) {
    $o = db()->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) AS pending, SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed, COALESCE(SUM(CASE WHEN payment_status='paid' THEN amount ELSE 0 END),0) AS paid FROM orders")->fetch();
  } else {
    $o = db()->query("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status='pending') AS pending, COUNT(*) FILTER (WHERE status='completed') AS completed, COALESCE(SUM(amount) FILTER (WHERE payment_status='paid'),0) AS paid FROM orders")->fetch();
  }
  $c = db()->query('SELECT COUNT(*) AS total FROM contact_messages')->fetch();
  $u = db()->query('SELECT COUNT(*) AS total FROM users')->fetch();
  json_response(['stats'=>[
    'orders'=>(int)$o['total'],
    'pending_orders'=>(int)$o['pending'],
    'completed_orders'=>(int)$o['completed'],
    'paid_amount'=>(int)$o['paid'],
    'contacts'=>(int)$c['total'],
    'users'=>(int)$u['total']
  ]]);
}

if ($path==='/api/admin/users' && $method==='GET') {
  require_admin();
  json_response(['users'=>db()->query('SELECT id,name,email,phone,role,created_at FROM users ORDER BY created_at DESC')->fetchAll()]);
}

if (preg_match('#^/api/admin/users/([0-9a-f-]+)$#',$path,$m) && $method==='GET') {
  require_admin();
  $st = db()->prepare('SELECT id,name,email,phone,role,created_at FROM users WHERE id=?');
  $st->execute([$m[1]]);
  $u = $st->fetch();
  if (!$u) json_response(['error'=>'کاربر پیدا نشد.'],404);
  $st = db()->prepare('SELECT id,package_name,project_type,description,budget,deadline,status,payment_status,amount,items,attachment_original_name,created_at,updated_at FROM orders WHERE user_id=? ORDER BY created_at DESC');
  $st->execute([$u['id']]);
  $orders = $st->fetchAll();
  $st = db()->prepare('SELECT id,name,email,message,created_at FROM contact_messages WHERE email=? ORDER BY created_at DESC');
  $st->execute([$u['email']]);
  $messages = $st->fetchAll();
  $orderIds = array_column($orders, 'id');
  $payments = [];
  if ($orderIds) {
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $st = db()->prepare("SELECT id,order_id,provider,authority,ref_id,status,amount,created_at FROM payments WHERE order_id IN ($ph) ORDER BY created_at DESC");
    $st->execute($orderIds);
    $payments = $st->fetchAll();
  }
  json_response(['user'=>$u,'orders'=>$orders,'messages'=>$messages,'payments'=>$payments]);
}

if ($path==='/api/admin/users' && $method==='POST') {
  require_admin();
  $name = trim((string)($body['name']??''));
  $email = norm_email($body['email']??'');
  $phone = trim((string)($body['phone']??''));
  $pass = (string)($body['password']??'');
  $role = ($body['role']??'')==='admin' ? 'admin' : 'user';
  if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8) {
    json_response(['error'=>'نام، ایمیل و رمز عبور حداقل ۸ کاراکتری الزامی است.'],400);
  }
  try {
    if (is_sqlite()) {
      $id = new_id();
      $now = date('Y-m-d H:i:s');
      db()->prepare('INSERT INTO users(id,name,email,phone,password_hash,role,created_at) VALUES(?,?,?,?,?,?,?)')
        ->execute([$id,$name,$email,$phone!==''?$phone:null,password_hash($pass,PASSWORD_DEFAULT),$role,$now]);
      $st = db()->prepare('SELECT id,name,email,phone,role,created_at FROM users WHERE id=?');
      $st->execute([$id]);
      json_response(['user'=>$st->fetch()],201);
    } else {
      $st = db()->prepare('INSERT INTO users(name,email,phone,password_hash,role) VALUES(?,?,?,?,?) RETURNING id,name,email,phone,role,created_at');
      $st->execute([$name,$email,$phone!==''?$phone:null,password_hash($pass,PASSWORD_DEFAULT),$role]);
      json_response(['user'=>$st->fetch()],201);
    }
  } catch (PDOException $e) {
    if ((string)$e->getCode()==='23505' || str_contains($e->getMessage(),'UNIQUE') || str_contains($e->getMessage(),'unique')) {
      json_response(['error'=>'این ایمیل قبلاً ثبت شده است.'],409);
    }
    throw $e;
  }
}

if (preg_match('#^/api/admin/users/([0-9a-f-]+)/role$#',$path,$m) && $method==='PATCH') {
  require_admin();
  $role = $body['role'] ?? '';
  if (!in_array($role, ['user','admin'], true)) json_response(['error'=>'نقش نامعتبر است.'],400);
  $st = db()->prepare('SELECT id,email,role FROM users WHERE id=?');
  $st->execute([$m[1]]);
  $r = $st->fetch();
  if (!$r) json_response(['error'=>'کاربر پیدا نشد.'],404);
  if ($r['email'] === norm_email(envv('ADMIN_EMAIL','')) && $role !== 'admin') {
    json_response(['error'=>'مدیر اصلی قابل حذف از نقش ادمین نیست.'],400);
  }
  if (is_sqlite()) {
    db()->prepare('UPDATE users SET role=? WHERE id=?')->execute([$role,$m[1]]);
    $st = db()->prepare('SELECT id,name,email,phone,role,created_at FROM users WHERE id=?');
    $st->execute([$m[1]]);
    json_response(['user'=>$st->fetch()]);
  } else {
    $st = db()->prepare('UPDATE users SET role=? WHERE id=? RETURNING id,name,email,phone,role,created_at');
    $st->execute([$role,$m[1]]);
    json_response(['user'=>$st->fetch()]);
  }
}

if (preg_match('#^/api/admin/users/([0-9a-f-]+)/reset-password$#',$path,$m) && $method==='POST') {
  require_admin();
  $pass = (string)($body['password']??'');
  if (strlen($pass) < 8) json_response(['error'=>'رمز عبور باید حداقل ۸ کاراکتر باشد.'],400);
  if (is_sqlite()) {
    db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($pass,PASSWORD_DEFAULT),$m[1]]);
    $st = db()->prepare('SELECT id,name,email,role FROM users WHERE id=?');
    $st->execute([$m[1]]);
    $r = $st->fetch();
  } else {
    $st = db()->prepare('UPDATE users SET password_hash=? WHERE id=? RETURNING id,name,email,role');
    $st->execute([password_hash($pass,PASSWORD_DEFAULT),$m[1]]);
    $r = $st->fetch();
  }
  if (!$r) json_response(['error'=>'کاربر پیدا نشد.'],404);
  json_response(['user'=>$r,'message'=>'رمز عبور کاربر تغییر کرد.']);
}

if ($path==='/api/admin/agent' && $method==='POST') {
  $u = require_admin();
  $prompt = trim((string)($body['prompt']??''));
  if (!$prompt) json_response(['error'=>'prompt الزامی است.'],400);
  $key = trim((string)envv('OPENAI_API_KEY',''));
  $response = '';
  if ($key) {
    $payload = json_encode([
      'model' => envv('OPENAI_MODEL','gpt-5-mini'),
      'input' => "تو ایجنت فنی سایت امیرعلی هستی. کد را مستقیم روی production تغییر نده. مشکل را تحلیل کن، فایل‌های احتمالی، علت، patch پیشنهادی و تست‌های لازم را دقیق بده.\n\n".$prompt
    ], JSON_UNESCAPED_UNICODE);
    $ch = curl_init('https://api.openai.com/v1/responses');
    curl_setopt_array($ch, [
      CURLOPT_POST => 1,
      CURLOPT_RETURNTRANSFER => 1,
      CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$key,'Content-Type: application/json'],
      CURLOPT_POSTFIELDS => $payload
    ]);
    $raw = curl_exec($ch);
    $hc = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$raw, true);
    $response = ($hc>=200 && $hc<300) ? ($j['output'][0]['content'][0]['text'] ?? $j['output_text'] ?? '') : 'AI در دسترس نبود.';
  } else {
    $response = "OPENAI_API_KEY تنظیم نشده است. برای فعال‌کردن ایجنت، کلید API را در .env قرار بده.\n\nتحلیل ورودی:\n".$prompt;
  }
  if (is_sqlite()) {
    db()->prepare('INSERT INTO agent_logs(id,user_id,prompt,response,created_at) VALUES(?,?,?,?,?)')
      ->execute([new_id(),$u['id'],$prompt,$response,date('Y-m-d H:i:s')]);
  } else {
    db()->prepare('INSERT INTO agent_logs(user_id,prompt,response) VALUES(?,?,?)')->execute([$u['id'],$prompt,$response]);
  }
  json_response(['response'=>$response]);
}

json_response(['error'=>'NOT_FOUND','path'=>$path],404);

} catch (Throwable $e) {
  error_log((string)$e);
  if ($path==='/api/health') json_response(['ok'=>false,'database'=>false,'error'=>'DATABASE_UNAVAILABLE'],503);
  $resp = ['error'=>'خطای سرور'];
  if (strtolower((string)envv('APP_DEBUG','false')) === 'true') {
    $resp['debug'] = $e->getMessage();
  }
  json_response($resp, 500);
}
