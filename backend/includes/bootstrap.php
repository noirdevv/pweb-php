<?php
declare(strict_types=1);

// ---------- Helper konfigurasi & database ----------
function cfg(string $path, $default = null) {
    $node = $GLOBALS['CFG'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) return $default;
        $node = $node[$key];
    }
    return $node;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg('db');
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}

// ---------- Helper respons ----------
function json_out($data = null, int $status = 200, array $extra = []): void {
    http_response_code($status);
    $out = ['ok' => true, 'data' => $data] + $extra;
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 400, array $errors = []): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'message' => $message, 'errors' => (object)$errors], JSON_UNESCAPED_UNICODE);
    exit;
}

function only(string ...$methods): string {
    $m = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($m, $methods, true)) fail('Metode tidak diizinkan', 405);
    return $m;
}

function body(): array {
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = ($raw !== false && $raw !== '') ? json_decode($raw, true) : [];
        if (!is_array($b)) fail('Format JSON tidak valid', 400);
    }
    return $b;
}

function str_field(array $src, string $key): string {
    $v = $src[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

// ---------- Sesi, autentikasi, CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_rotate(): string {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_verify(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        fail('Token keamanan tidak valid. Muat ulang halaman.', 419);
    }
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    static $user = false;
    if ($user === false) {
        $st = db()->prepare('SELECT id, nama, email, role FROM users WHERE id = ?');
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
        if ($user === null) unset($_SESSION['uid']);
    }
    return $user;
}

function require_login(): array {
    $u = current_user();
    if (!$u) fail('Silakan login terlebih dahulu', 401);
    return $u;
}

function require_role(string $role): array {
    $u = require_login();
    if ($u['role'] !== $role) fail('Anda tidak memiliki akses', 403);
    return $u;
}

function client_ip(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45);
}

function frontend_url(string $path = ''): string {
    $base = cfg('app.frontend_url');
    if (!$base) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $root  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/', 3)), '/');
        $base  = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $root . '/frontend';
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

// ---------- Inisialisasi ----------
$GLOBALS['CFG'] = require __DIR__ . '/../config/config.php';
date_default_timezone_set(cfg('app.timezone', 'Asia/Jakarta'));

ini_set('display_errors', cfg('app.debug') ? '1' : '0');
set_exception_handler(function (Throwable $e) {
    error_log('[PSB] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => cfg('app.debug') ? $e->getMessage() : 'Terjadi kesalahan pada server', 'errors' => new stdClass()]);
});

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, cfg('security.allowed_origins', []), true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Vary: Origin');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

ini_set('session.use_strict_mode', '1');
session_name(cfg('security.session_name', 'PSBSESSID'));
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => (bool)cfg('security.cookie_secure', false),
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true) && !defined('SKIP_CSRF')) {
    csrf_verify();
}
