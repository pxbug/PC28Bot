<?php
/**
 * Public entry point — all requests go here.
 * Web server root points to admin/ directory.
 *
 * Routing: /page-name  → page=page-name
 *   Supports clean URLs for both GET and POST via Nginx try_files.
 */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Auth.php';

DB::init(__DIR__ . '/../data/admin.db');
Auth::start();
Auth::seedAdmin('admin', 'admin123', 'SuperAdmin', 'super');

// ── Route ────────────────────────────────────────────────────────────────────
// Always check $_GET['page'] first (e.g. ?page=config POST submissions)
// Fallback: parse the URI path so Nginx rewrite works for POST too
$page = $_GET['page'] ?? null;
if (!$page) {
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $page = trim($uri, '/');
    // Strip /public prefix if present (when root is admin/)
    $page = preg_replace('#^public/#', '', $page);
    $page = $page ?: 'dashboard';
}

$authFree = in_array($page, ['login', 'logout']);

if (!$authFree) {
    Auth::require();
}

switch ($page) {

    // ── Public ──────────────────────────────────────────────────────────────
    case 'login':
        require __DIR__ . '/login.php';
        break;

    case 'logout':
        Auth::logout();
        header('Location: ?page=login');
        exit;

    // ── Authenticated ───────────────────────────────────────────────────────
    case 'dashboard':
    case 'users':
    case 'bets':
    case 'deposits':
    case 'withdrawals':
    case 'lottery':
    case 'config':
    case 'stats':
        ob_start();
        $pageFile = __DIR__ . '/pages/' . $page . '.php';
        if (is_file($pageFile)) {
            require $pageFile;
        } else {
            echo '<div class="empty-state"><div class="empty-state-title">页面建设中</div></div>';
        }
        $pageContent = ob_get_clean();
        $currentPage = $page;
        require __DIR__ . '/layout.php';
        break;

    case 'user_detail':
        $uid = intval($_GET['id'] ?? 0);
        if ($uid <= 0) {
            http_response_code(400);
            echo '<h1>400 — 缺少用户ID</h1>';
            break;
        }
        ob_start();
        require __DIR__ . '/pages/user_detail.php';
        $pageContent = ob_get_clean();
        $currentPage = 'user_detail';
        require __DIR__ . '/layout.php';
        break;

    default:
        http_response_code(404);
        echo '<h1>404 — 页面不存在</h1>';
        break;
}
