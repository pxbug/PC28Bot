<?php
/**
 * Single entry point — no routing rules needed.
 *
 * With Nginx "try_files $uri $uri/ /index.php?$query_string":
 *   /login      → index.php?page=login
 *   /users      → index.php?page=users
 *   /users?id=5 → index.php?page=user_detail&id=5
 *
 * Or directly:
 *   index.php?page=dashboard
 *   index.php?page=login
 */

require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Auth.php';

DB::init(__DIR__ . '/data/admin.db');
Auth::start();
Auth::seedAdmin('admin', 'admin123', 'SuperAdmin', 'super');

// ── Route ────────────────────────────────────────────────────────────────────
$page = $_GET['page'] ?? 'dashboard';
$authFree = in_array($page, ['login', 'logout']);

if (!$authFree) {
    Auth::require();
}

switch ($page) {

    // ── Public ──────────────────────────────────────────────────────────────
    case 'login':
        require __DIR__ . '/public/login.php';
        break;

    case 'logout':
        Auth::logout();
        header('Location: index.php?page=login');
        exit;

    // ── Authenticated ──────────────────────────────────────────────────────
    case 'dashboard':
    case 'users':
    case 'bets':
    case 'deposits':
    case 'withdrawals':
    case 'lottery':
    case 'config':
    case 'stats':
        require __DIR__ . '/public/layout.php';
        break;

    case 'user_detail':
        $uid = intval($_GET['id'] ?? 0);
        if ($uid <= 0) {
            http_response_code(400);
            echo '<h1>400 — 缺少用户ID</h1>';
            break;
        }
        require __DIR__ . '/public/layout.php';
        break;

    default:
        http_response_code(404);
        echo '<h1>404 — 页面不存在</h1>';
        break;
}
