<?php
/**
 * PC28 Admin — 前台页面路由
 *
 * 干净 URL：
 *   /index.php              → dashboard
 *   /index.php?page=users
 *   /index.php?page=bets
 *   /index.php?page=recharges
 *   /index.php?page=withdraws
 *   /index.php?page=rebates
 *   /index.php?page=lottery
 *   /index.php?page=config
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Helper;

Auth::require();

$user = Auth::user();
$page = trim($_GET['page'] ?? 'dashboard');

// 允许的页面（白名单）
$allowed = [
    'dashboard', 'users', 'bets', 'recharges',
    'withdraws', 'rebates', 'lottery', 'config',
];
if (!in_array($page, $allowed)) {
    $page = 'dashboard';
}

// 页面标题
$titles = [
    'dashboard'  => '仪表盘',
    'users'     => '用户管理',
    'bets'      => '下注记录',
    'recharges' => '充值记录',
    'withdraws' => '提现记录',
    'rebates'   => '反水记录',
    'lottery'   => '开奖管理',
    'config'    => '系统配置',
];

$toast = Helper::flash('toast');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= Helper::h($titles[$page]) ?> — PC28 管理后台</title>
<link rel="stylesheet" href="/assets/css/apple.css">
</head>
<body>

<!-- 侧边栏 -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">P</div>
        <span class="sidebar-title">PC28 后台</span>
    </div>
    <div class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-title">概况</div>
            <a href="/index.php?page=dashboard" class="nav-item <?= $page==='dashboard'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                仪表盘
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">运营</div>
            <a href="/index.php?page=users" class="nav-item <?= $page==='users'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                用户管理
            </a>
            <a href="/index.php?page=recharges" class="nav-item <?= $page==='recharges'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                充值记录
            </a>
            <a href="/index.php?page=withdraws" class="nav-item <?= $page==='withdraws'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                提现记录
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">游戏</div>
            <a href="/index.php?page=bets" class="nav-item <?= $page==='bets'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                下注记录
            </a>
            <a href="/index.php?page=rebates" class="nav-item <?= $page==='rebates'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/></svg>
                反水记录
            </a>
            <a href="/index.php?page=lottery" class="nav-item <?= $page==='lottery'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                开奖管理
            </a>
        </div>
        <div class="nav-section">
            <div class="nav-section-title">系统</div>
            <a href="/index.php?page=config" class="nav-item <?= $page==='config'?'active':'' ?>">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                系统配置
            </a>
        </div>
    </div>
    <div class="sidebar-footer">
        <div class="flex items-center gap-2">
            <div style="width:28px;height:28px;background:linear-gradient(135deg,var(--blue),var(--indigo));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;color:#fff;font-weight:700;flex-shrink:0">
                <?= strtoupper(mb_substr(Helper::h($user['username']), 0, 1)) ?>
            </div>
            <div class="flex-1 min-w-0">
                <div class="text-sm" style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= Helper::h($user['username']) ?></div>
                <div class="text-xs text-muted">管理员</div>
            </div>
            <a href="/logout.php" title="退出登录" style="color:var(--text-tertiary);display:flex;align-items:center">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            </a>
        </div>
    </div>
</nav>

<!-- 主内容 -->
<div class="main-wrap">
    <header class="topbar">
        <div class="topbar-title"><?= Helper::h($titles[$page]) ?></div>
        <div class="topbar-actions">
            <span class="text-sm text-muted"><?= date('Y-m-d') ?></span>
        </div>
    </header>

    <main class="page-content fade-in">
        <?php include __DIR__ . '/../views/pages/' . $page . '.php'; ?>
    </main>
</div>

<script src="/assets/js/app.js"></script>
<?php if ($toast): ?>
<script>toast(<?= json_encode($toast) ?>, 'success');</script>
<?php endif; ?>
</body>
</html>