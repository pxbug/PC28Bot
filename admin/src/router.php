<?php
/**
 * Minimal front-controller router
 * Maps clean URLs to page controllers:
 *   /          → dashboard
 *   /login     → login page
 *   /logout    → logout action
 *   /users     → user list
 *   /users/{id} → user detail
 *   /bets      → bet records
 *   /deposits  → deposit records
 *   /withdrawals → withdrawal records
 *   /lottery   → lottery history + push control
 *   /config    → system config
 *   /stats     → stats / reports
 * All other static files are served from /public/ as-is.
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
if ($uri !== '/' && str_ends_with($uri, '/')) {
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
if (str_starts_with($uri, '/api/bot/')) {
    require_once __DIR__ . '/api/bot.php';
    exit;
}

// ── Page routing ──────────────────────────────────────────────────────────────
$page = match (true) {
    $uri === '/login' && $method === 'GET'  => ['__login__'],
    $uri === '/login' && $method === 'POST' => ['__login_post__'],
    $uri === '/logout'                      => ['__logout__'],
    $uri === '/'                            => ['dashboard', 'render'],
    $uri === '/users'                       => ['users', 'render'],
    preg_match('#^/users/(\d+)$#', $uri, $m) => ['user_detail', 'render', $m[1]],
    $uri === '/bets'                        => ['bets', 'render'],
    $uri === '/deposits'                    => ['deposits', 'render'],
    $uri === '/withdrawals'                => ['withdrawals', 'render'],
    $uri === '/lottery'                     => ['lottery', 'render'],
    $uri === '/config'                      => ['config', 'render'],
    $uri === '/stats'                       => ['stats', 'render'],
    default                                 => null,
};

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

array_shift($page); // drop page name
$controller = array_shift($page);
$param = $page[0] ?? null;

require_once __DIR__ . '/../public/layout.php';

$contentFile = __DIR__ . '/../public/pages/' . $controller . '.php';
if (is_file($contentFile)) {
    include $contentFile;
} else {
    echo '<div class="page-content"><div class="empty-state"><div class="empty-state-title">页面建设中</div></div></div>';
}
