<?php
/**
 * Minimal front-controller router (PHP 7.4+ compatible)
 * Maps clean URLs to page controllers.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

DB::init(__DIR__ . '/../data/admin.db');
Auth::start();

// Auto-create superadmin on first run
Auth::seedAdmin('admin', 'admin123', 'SuperAdmin', 'super');

// ── Dispatch ──────────────────────────────────────────────────────────────────
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Remove trailing slashes (except root)
if ($uri !== '/' && substr($uri, -1) === '/') {
    header('Location: ' . rtrim($uri, '/'));
    exit;
}

// ── Static / public assets ─────────────────────────────────────────────────────
if (preg_match('#^/css/#', $uri) || preg_match('#^/js/#', $uri) || preg_match('#^/assets/#', $uri)) {
    $file = __DIR__ . '/../public' . $uri;
    if (is_file($file)) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime = [
            'css' => 'text/css', 'js' => 'application/javascript',
            'png' => 'image/png', 'jpg' => 'image/jpeg',
            'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'woff2' => 'font/woff2',
        ][$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// ── API endpoints ─────────────────────────────────────────────────────────────
if (substr($uri, 0, 9) === '/api/bot/') {
    require_once __DIR__ . '/api/bot.php';
    exit;
}

// ── Page routing ──────────────────────────────────────────────────────────────
$page = null;

if ($uri === '/login' && $method === 'GET') {
    $page = ['__login__'];
} elseif ($uri === '/login' && $method === 'POST') {
    $page = ['__login_post__'];
} elseif ($uri === '/logout') {
    $page = ['__logout__'];
} elseif ($uri === '/') {
    $page = ['dashboard', 'render'];
} elseif ($uri === '/users') {
    $page = ['users', 'render'];
} elseif (preg_match('#^/users/(\d+)$#', $uri, $m)) {
    $page = ['user_detail', 'render', $m[1]];
} elseif ($uri === '/bets') {
    $page = ['bets', 'render'];
} elseif ($uri === '/deposits') {
    $page = ['deposits', 'render'];
} elseif ($uri === '/withdrawals') {
    $page = ['withdrawals', 'render'];
} elseif ($uri === '/lottery') {
    $page = ['lottery', 'render'];
} elseif ($uri === '/config') {
    $page = ['config', 'render'];
} elseif ($uri === '/stats') {
    $page = ['stats', 'render'];
}

if ($page === null) {
    http_response_code(404);
    echo '<h1>404 Not Found</h1>';
    exit;
}

$authFree = in_array($uri, ['/login', '/logout']);
if (!$authFree) {
    Auth::require();
}

if ($page[0] === '__login__') {
    require_once __DIR__ . '/../public/login.php';
    exit;
}

if ($page[0] === '__login_post__') {
    require_once __DIR__ . '/../public/login.php';
    exit;
}

if ($page[0] === '__logout__') {
    Auth::logout();
    header('Location: /login');
    exit;
}

array_shift($page);
$controller = array_shift($page);
$param = $page[0] ?? null;

require_once __DIR__ . '/../public/layout.php';

$contentFile = __DIR__ . '/../public/pages/' . $controller . '.php';
if (is_file($contentFile)) {
    include $contentFile;
} else {
    echo '<div class="page-content"><div class="empty-state"><div class="empty-state-title">页面建设中</div></div></div>';
}
