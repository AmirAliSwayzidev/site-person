<?php
declare(strict_types=1);

$envFile = dirname(__DIR__) . '/.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (($v[0] ?? '') === '"' && substr($v, -1) === '"') $v = substr($v, 1, -1);
        $_ENV[$k] = $v;
    }
}

function envv(string $key, ?string $default = null): ?string {
    return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
}

function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return false;
}

/**
 * SQLite داخل پوشه data پروژه — بدون نیاز به ساخت دیتابیس در cPanel.
 * اگر DATABASE_URL خالی باشد یا با sqlite: شروع شود، از همین استفاده می‌شود.
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = trim((string)envv('DATABASE_URL', ''));
    $useSqlite = ($url === '' || str_starts_with($url, 'sqlite:'));

    if (!$useSqlite) {
        $p = parse_url($url);
        if (!$p || empty($p['host']) || empty($p['user']) || !isset($p['path'])) {
            throw new RuntimeException('Invalid DATABASE_URL');
        }
        $dsn = 'pgsql:host=' . $p['host']
            . (isset($p['port']) ? ';port=' . $p['port'] : '')
            . ';dbname=' . ltrim($p['path'], '/');
        if (isset($p['query'])) {
            parse_str($p['query'], $q);
            if (!empty($q['sslmode'])) $dsn .= ';sslmode=' . $q['sslmode'];
        }
        $pdo = new PDO($dsn, $p['user'], $p['pass'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    }

    $path = ($url !== '' && str_starts_with($url, 'sqlite:'))
        ? substr($url, 7)
        : (dirname(__DIR__) . '/data/app.db');
    if ($path === '' || $path === ':memory:') {
        $path = dirname(__DIR__) . '/data/app.db';
    }
    if ($path[0] !== '/' && !preg_match('#^[A-Za-z]:[\\\\/]#', $path)) {
        $path = dirname(__DIR__) . '/' . ltrim($path, './');
    }

    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException(
            'پوشه data قابل نوشتن نیست. در File Manager روی پوشه data دسترسی 755 یا 775 بگذارید.'
        );
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = DELETE');
    ensure_sqlite_schema($pdo);
    return $pdo;
}

function ensure_sqlite_schema(PDO $pdo): void {
    $pdo->exec("
CREATE TABLE IF NOT EXISTS users (
 id TEXT PRIMARY KEY,
 email TEXT UNIQUE NOT NULL,
 name TEXT NOT NULL,
 phone TEXT,
 password_hash TEXT NOT NULL,
 role TEXT NOT NULL DEFAULT 'user',
 created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS email_verifications (
 id TEXT PRIMARY KEY,
 email TEXT NOT NULL,
 purpose TEXT NOT NULL CHECK(purpose IN ('signup','reset')),
 code_hash TEXT NOT NULL,
 expires_at TEXT NOT NULL,
 attempts INTEGER NOT NULL DEFAULT 0,
 verified_at TEXT,
 created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS email_verifications_lookup ON email_verifications(email, purpose, created_at DESC);
CREATE TABLE IF NOT EXISTS orders (
 id TEXT PRIMARY KEY,
 user_id TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
 package_name TEXT,
 project_type TEXT,
 description TEXT NOT NULL,
 budget TEXT,
 deadline TEXT,
 features TEXT NOT NULL DEFAULT '[]',
 contact_name TEXT,
 contact_phone TEXT,
 status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','in_progress','completed','cancelled')),
 payment_status TEXT NOT NULL DEFAULT 'unpaid' CHECK(payment_status IN ('unpaid','pending','paid','failed','refunded')),
 amount INTEGER,
 items TEXT NOT NULL DEFAULT '[]',
 attachment_original_name TEXT,
 attachment_path TEXT,
 attachment_mime TEXT,
 created_at TEXT NOT NULL DEFAULT (datetime('now')),
 updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS contact_messages (
 id TEXT PRIMARY KEY,
 user_id TEXT REFERENCES users(id) ON DELETE SET NULL,
 name TEXT NOT NULL,
 email TEXT NOT NULL,
 message TEXT NOT NULL,
 created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS payments (
 id TEXT PRIMARY KEY,
 order_id TEXT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
 provider TEXT NOT NULL DEFAULT 'manual',
 authority TEXT,
 ref_id TEXT,
 status TEXT NOT NULL DEFAULT 'created',
 amount INTEGER,
 created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE TABLE IF NOT EXISTS agent_logs (
 id TEXT PRIMARY KEY,
 user_id TEXT REFERENCES users(id) ON DELETE SET NULL,
 prompt TEXT NOT NULL,
 response TEXT,
 created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
");
}

function new_id(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

function is_sqlite(): bool {
    static $v = null;
    if ($v !== null) return $v;
    try {
        $v = db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    } catch (Throwable $e) {
        $v = true;
    }
    return $v;
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function parse_request_body(): array {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) return input_json();
    if (str_contains($ct, 'multipart/form-data') || str_contains($ct, 'application/x-www-form-urlencoded')) return $_POST;
    $raw = trim((string)file_get_contents('php://input'));
    if ($raw !== '' && ($raw[0] === '{' || $raw[0] === '[')) {
        $data = json_decode($raw, true);
        if (is_array($data)) return $data;
    }
    return $_POST;
}

function norm_email($e): string {
    return strtolower(trim((string)$e));
}

function norm_phone($p): string {
    $p = trim((string)$p);
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $p = str_replace($fa, $en, $p);
    $p = str_replace($ar, $en, $p);
    $p = preg_replace('/[\s\-\(\)]/', '', $p) ?? '';
    // Normalize Iranian mobiles to 09xxxxxxxxx form
    if (preg_match('/^(?:\+98|98|0)?(9\d{9})$/', $p, $m)) {
        return '0' . $m[1];
    }
    return $p;
}

function hash_code(string $code): string {
    return hash_hmac('sha256', $code, (string)envv('APP_SECRET', 'change-this-secret'));
}

function public_user(array $u): array {
    return [
        'id' => $u['id'],
        'email' => $u['email'],
        'name' => $u['name'],
        'phone' => $u['phone'] ?? null,
        'role' => $u['role'],
        'created_at' => $u['created_at'],
    ];
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = is_https();
    session_name('amirali_session');

    $savePath = session_save_path();
    if ($savePath === '' || !is_dir($savePath) || !is_writable($savePath)) {
        $fallback = dirname(__DIR__) . '/data/sessions';
        if (!is_dir($fallback)) @mkdir($fallback, 0755, true);
        if (is_dir($fallback) && is_writable($fallback)) {
            session_save_path($fallback);
        }
    }

    session_set_cookie_params([
        'lifetime' => 7 * 24 * 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

function require_auth(): array {
    start_secure_session();
    if (empty($_SESSION['user_id'])) json_response(['error' => 'AUTH_REQUIRED'], 401);
    $st = db()->prepare('SELECT id,email,name,phone,role,created_at FROM users WHERE id=?');
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u) {
        $_SESSION = [];
        session_destroy();
        json_response(['error' => 'AUTH_REQUIRED'], 401);
    }
    return $u;
}

function require_admin(): array {
    $u = require_auth();
    if ($u['role'] !== 'admin') json_response(['error' => 'ADMIN_ONLY'], 403);
    return $u;
}

function same_origin_check(): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PATCH', 'PUT', 'DELETE'], true)) return;
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($origin !== '') {
        $o = parse_url($origin);
        $originHost = strtolower((string)($o['host'] ?? ''));
        $reqHost = strtolower(preg_replace('/:\d+$/', '', $host) ?? '');
        if ($originHost !== '' && $reqHost !== '' && $originHost !== $reqHost) {
            json_response(['error' => 'ORIGIN_FORBIDDEN'], 403);
        }
    }
}

function rate_limit(string $key, int $max, int $window): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $dir = dirname(__DIR__) . '/data/rate';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $file = $dir . '/' . hash('sha256', $key . '|' . $ip) . '.json';
    $now = time();
    $d = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    if (!is_array($d) || $now - $d['start'] > $window) $d = ['start' => $now, 'count' => 0];
    $d['count']++;
    @file_put_contents($file, json_encode($d), LOCK_EX);
    if ($d['count'] > $max) {
        header('Retry-After: ' . strval(max(1, $d['start'] + $window - $now)));
        json_response(['error' => 'تعداد درخواست‌ها زیاد است. لطفاً کمی بعد دوباره تلاش کنید.'], 429);
    }
}

function clear_rate_limit(string $key): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $file = dirname(__DIR__) . '/data/rate/' . hash('sha256', $key . '|' . $ip) . '.json';
    if (is_file($file)) @unlink($file);
}

function issue_verification_token(string $email, string $purpose): string {
    start_secure_session();
    $token = bin2hex(random_bytes(32));
    $_SESSION['verified_tokens'][$purpose] = ['token' => $token, 'email' => $email, 'expires' => time() + 900];
    return $token;
}

/** ارسال کد تایید — هم برای ثبت‌نام (signup) و هم بازیابی رمز (reset) — از Resend */
function send_verification_email(string $to, string $code, string $purpose = 'reset'): void {
    $key = trim((string)envv('RESEND_API_KEY', ''));
    $from = trim((string)envv('MAIL_FROM', ''));
    if (!$key || !$from) {
        throw new RuntimeException('EMAIL_NOT_CONFIGURED');
    }
    $isSignup = $purpose === 'signup';
    $subject = $isSignup ? 'کد تایید ثبت‌نام — AMIRALI' : 'کد بازیابی رمز عبور — AMIRALI';
    $title = $isSignup ? 'تایید ایمیل' : 'بازیابی رمز عبور';
    $desc = $isSignup
        ? 'برای تکمیل ثبت‌نام، کد زیر را در سایت وارد کنید:'
        : 'برای تغییر رمز عبور حساب شما درخواست ثبت شده است. کد زیر را وارد کنید:';
    $html = '<!doctype html><html lang="fa" dir="rtl"><body style="margin:0;background:#070b16;color:#eaf0ff;font-family:Arial,sans-serif;padding:32px">'
        . '<div style="max-width:560px;margin:auto;background:#0d1425;border:1px solid #263553;border-radius:20px;padding:32px">'
        . '<h2>' . $title . '</h2>'
        . '<p>' . $desc . '</p>'
        . '<div style="font-size:34px;letter-spacing:10px;text-align:center;font-weight:800;background:#121d34;border-radius:14px;padding:20px;margin:26px 0;direction:ltr">'
        . htmlspecialchars($code, ENT_QUOTES, 'UTF-8')
        . '</div>'
        . '<p style="color:#9aa8c2">این کد تا ۱۰ دقیقه معتبر است. اگر شما این درخواست را نداده‌اید، این پیام را نادیده بگیرید.</p>'
        . '<p style="color:#66748e;font-size:12px">AMIRALI</p></div></body></html>';

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'from' => $from,
            'to' => [$to],
            'subject' => $subject,
            'html' => $html,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $codeHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $codeHttp < 200 || $codeHttp >= 300) {
        error_log('RESEND_FAIL http=' . $codeHttp . ' err=' . $err . ' body=' . (string)$body);
        throw new RuntimeException('EMAIL_SEND_FAILED');
    }
}


function send_telegram_receipt(string $filePath, string $caption): bool {
    $token = trim((string)envv('TELEGRAM_BOT_TOKEN', ''));
    $chatId = trim((string)envv('TELEGRAM_CHAT_ID', ''));
    if ($chatId === '') {
        $username = trim((string)envv('TELEGRAM_USERNAME', ''));
        if ($username !== '') $chatId = '@' . ltrim($username, '@');
    }
    if ($token === '' || $chatId === '' || !is_file($filePath)) {
        error_log('TELEGRAM_RECEIPT_NOT_CONFIGURED');
        return false;
    }

    $url = 'https://api.telegram.org/bot' . $token . '/sendDocument';
    $mime = function_exists('mime_content_type') ? (mime_content_type($filePath) ?: 'application/octet-stream') : 'application/octet-stream';
    $name = basename($filePath);
    $post = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'document' => new CURLFile($filePath, $mime, $name),
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false || $http < 200 || $http >= 300) {
        error_log('TELEGRAM_RECEIPT_FAIL http=' . $http . ' err=' . $err . ' body=' . (string)$raw);
        return false;
    }
    $json = json_decode((string)$raw, true);
    if (!is_array($json) || empty($json['ok'])) {
        error_log('TELEGRAM_RECEIPT_FAIL body=' . (string)$raw);
        return false;
    }
    return true;
}

function safe_json($v, $default = []) {
    if (is_array($v)) return $v;
    if (!is_string($v) || $v === '') return $default;
    $x = json_decode($v, true);
    return is_array($x) ? $x : $default;
}

function api_path(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if (isset($_SERVER['PATH_INFO']) && $_SERVER['PATH_INFO'] !== '') {
        $pi = $_SERVER['PATH_INFO'];
        if (str_starts_with($path, '/api') && !str_contains($path, '/auth') && !str_contains($path, '/orders')) {
            $path = '/api' . $pi;
        }
    }
    if ($path !== '/' && str_ends_with($path, '/')) $path = rtrim($path, '/');
    return $path;
}

start_secure_session();
same_origin_check();
