<?php
// Local PHP development router. cPanel/Apache uses .htaccess; PHP's built-in
// server does not read .htaccess, so this file mirrors the required rewrites.
declare(strict_types=1);
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$root = __DIR__;

if (str_starts_with($uri, '/api')) {
    require $root . '/api/index.php';
    return true;
}

$pretty = [
    '/' => '/index.html',
    '/auth' => '/auth.html',
    '/dashboard' => '/dashboard.html',
    '/admin' => '/admin.html',
    '/shop' => '/shop.html',
    '/request' => '/request.html',
    '/checkout' => '/checkout.html',
];
if (isset($pretty[$uri])) {
    $file = $root . $pretty[$uri];
    if (is_file($file)) { require $file; return true; }
}

// Protect private upload files during local testing too.
if (str_starts_with($uri, '/uploads/')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

$file = realpath($root . $uri);
$rootReal = realpath($root);
if ($file !== false && $rootReal !== false && str_starts_with($file, $rootReal . DIRECTORY_SEPARATOR) && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mime = [
        'css'=>'text/css; charset=utf-8','js'=>'application/javascript; charset=utf-8',
        'html'=>'text/html; charset=utf-8','json'=>'application/json; charset=utf-8',
        'xml'=>'application/xml; charset=utf-8','txt'=>'text/plain; charset=utf-8',
        'png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','webp'=>'image/webp','svg'=>'image/svg+xml'
    ][$ext] ?? null;
    if ($mime) header('Content-Type: '.$mime);
    readfile($file);
    return true;
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 Not Found';
return true;
