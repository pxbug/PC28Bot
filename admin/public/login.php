<?php
/**
 * 登录页
 */
require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Helper;

if (Auth::check()) {
    Helper::flash('toast', '已登录');
    Response::redirect('/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || $password === '') {
        $error = '请输入用户名和密码';
    } elseif (Auth::login($username, $password)) {
        Helper::flash('toast', '登录成功');
        Response::redirect('/index.php');
    } else {
        $error = '用户名或密码错误';
    }
}

$toast = Helper::flash('toast');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 — PC28 管理后台</title>
<link rel="stylesheet" href="/assets/css/apple.css">
</head>
<body>
<div class="login-wrap">
    <div class="login-card fade-in">
        <div class="login-logo">P</div>
        <h1 class="login-title">PC28 管理后台</h1>
        <p class="login-sub">请登录以继续</p>

        <?php if ($error): ?>
        <div style="background:rgba(255,59,48,.1);color:var(--red);padding:10px 14px;border-radius:var(--radius-md);font-size:13px;margin-bottom:16px">
            <?= Helper::h($error) ?>
        </div>
        <?php endif; ?>

        <form class="login-form" method="POST" autocomplete="off">
            <div class="input-group">
                <label class="input-label">用户名</label>
                <input type="text" name="username" class="input" placeholder="请输入用户名" required autofocus value="<?= Helper::h($_POST['username'] ?? '') ?>">
            </div>
            <div class="input-group">
                <label class="input-label">密码</label>
                <input type="password" name="password" class="input" placeholder="请输入密码" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-full" style="margin-top:4px">
                登 录
            </button>
        </form>
    </div>
</div>

<?php if ($toast): ?>
<script>alert(<?= json_encode($toast) ?>);</script>
<?php endif; ?>
</body>
</html>