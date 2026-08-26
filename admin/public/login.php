<?php
/**
 * Login page — loaded via router (public/index.php), not served directly.
 */
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '请输入用户名和密码';
    } elseif (!Auth::login($username, $password)) {
        $error = '用户名或密码错误';
    } else {
        header('Location: ?page=dashboard');
        exit;
    }
}

if (Auth::check()) {
    header('Location: ?page=dashboard');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 — PC28 Admin</title>
    <link rel="stylesheet" href="/css/app.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🎰</text></svg>">
</head>
<body>
<div class="login-page">
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-icon">P</div>
            <div class="login-title">PC28 Admin</div>
            <div class="login-sub">管理系统</div>
        </div>

        <?php if ($error): ?>
            <div class="login-error show"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form class="login-form" method="POST" autocomplete="off">
            <div class="form-group">
                <label class="form-label">用户名</label>
                <input type="text" name="username" class="form-input" placeholder="输入用户名" autofocus required>
            </div>
            <div class="form-group">
                <label class="form-label">密码</label>
                <input type="password" name="password" class="form-input" placeholder="输入密码" required>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="margin-top:8px;justify-content:center;">
                登 录
            </button>
        </form>

        <div style="text-align:center;margin-top:20px;font-size:12px;color:var(--text-tertiary);">
            默认账号: admin / admin123
        </div>
    </div>
</div>
</body>
</html>
