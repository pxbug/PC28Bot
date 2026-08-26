<?php
/**
 * Global layout template
 * All pages render inside this shell (except login page)
 */
$admin = Auth::admin();
$currentPage = $_GET['page'] ?? 'dashboard';

function navItem(string $page, string $icon, string $label, string $current, ?string $badge = null): string {
    $active = ($page === $current) ? 'active' : '';
    $badgeHtml = $badge !== null ? "<span class=\"nav-badge\">$badge</span>" : '';
    return "<a href=\"?page=$page\" class=\"nav-item $active\"><span class=\"nav-icon\">$icon</span>$label$badgeHtml</a>";
}

function pageTitle(string $title): string {
    return $title . ' — PC28 Admin';
}

function adminInit(): void {
    Auth::require();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? pageTitle($pageTitle) : 'PC28 Admin' ?></title>
    <link rel="stylesheet" href="/css/app.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎰</text></svg>">
</head>
<body>

<!-- ── Sidebar ───────────────────────────────────────────────────────── -->
<nav class="sidebar" id="sidebar">
    <div class="sidebar-logo">
        <div class="sidebar-logo-icon">P</div>
        <div>
            <div class="sidebar-logo-text">PC28 Admin</div>
            <div class="sidebar-logo-sub">管理系统</div>
        </div>
    </div>

    <div class="sidebar-nav">
        <div class="nav-section">
            <div class="nav-section-label">概览</div>
            <?= navItem('dashboard', '🖥',  '仪表盘',  $currentPage) ?>
            <?= navItem('stats',    '📊',  '数据统计', $currentPage) ?>
        </div>

        <div class="nav-section">
            <div class="nav-section-label">用户与交易</div>
            <?= navItem('users',       '👥', '用户管理', $currentPage) ?>
            <?= navItem('bets',        '🎲', '下注记录', $currentPage) ?>
            <?= navItem('deposits',    '💰', '充值管理', $currentPage) ?>
            <?= navItem('withdrawals', '🏧', '提现管理', $currentPage) ?>
        </div>

        <div class="nav-section">
            <div class="nav-section-label">系统</div>
            <?= navItem('lottery', '🔔', '开奖管理', $currentPage) ?>
            <?= navItem('config',  '⚙',  '系统配置', $currentPage) ?>
        </div>
    </div>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="sidebar-avatar"><?= mb_substr($admin['nickname'] ?? 'A', 0, 1) ?></div>
            <div class="sidebar-user-info">
                <div class="sidebar-user-name"><?= htmlspecialchars($admin['nickname'] ?? 'Admin') ?></div>
                <div class="sidebar-user-role"><?= $admin['role'] === 'super' ? '超级管理员' : '管理员' ?></div>
            </div>
            <a href="?page=logout" title="退出登录" class="icon-btn" style="margin-left:4px;">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                    <polyline points="16 17 21 12 16 7"/>
                    <line x1="21" y1="12" x2="9" y2="12"/>
                </svg>
            </a>
        </div>
    </div>
</nav>

<!-- ── Main ────────────────────────────────────────────────────────────── -->
<div class="main-content">
    <header class="topbar">
        <div class="topbar-breadcrumb">
            <a href="?page=dashboard">首页</a>
            <?php if (isset($breadcrumb)): foreach ($breadcrumb as $i => $crumb): ?>
                <span>›</span>
                <?php if ($i === count($breadcrumb) - 1): ?>
                    <span style="color:var(--text-primary)"><?= htmlspecialchars($crumb) ?></span>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($crumb[1]) ?>"><?= htmlspecialchars($crumb[0]) ?></a>
                <?php endif; ?>
            <?php endforeach; endif; ?>
        </div>
        <div class="topbar-spacer"></div>
        <div class="topbar-actions">
            <button class="icon-btn" onclick="location.reload()" title="刷新">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="23 4 23 10 17 10"/>
                    <polyline points="1 20 1 14 7 14"/>
                    <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>
                </svg>
            </button>
        </div>
    </header>

    <main class="page-content">
        <?php if (isset($error)): ?>
            <div class="login-error show" style="margin-bottom:16px"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if (isset($success)): ?>
            <div id="toast-success" style="display:none" data-msg="<?= htmlspecialchars($success) ?>"></div>
        <?php endif; ?>
        <!-- Page content injected by router -->
</main>
</div>

<!-- ── Toast ─────────────────────────────────────────────────────────── -->
<div class="toast-container" id="toast-container"></div>

<script src="/js/app.js"></script>
<?php if (isset($pageScript)): ?>
<script><?= $pageScript ?></script>
<?php endif; ?>
</body>
</html>
