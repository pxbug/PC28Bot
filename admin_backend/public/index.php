<?php
/**
 * 简易后台管理首页（HTML）
 */

require_once __DIR__ . '/../src/db.php';

$pdo = db();

// 统计
$totalUsers = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('user'))->fetch()['cnt'];
$totalBalance = $pdo->query("SELECT IFNULL(SUM(balance), 0) as s FROM " . table('user'))->fetch()['s'];
$todayBets = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('bet') . " WHERE DATE(created_at) = CURDATE()")->fetch()['cnt'];
$todayAmount = $pdo->query("SELECT IFNULL(SUM(amount), 0) as s FROM " . table('bet') . " WHERE DATE(created_at) = CURDATE()")->fetch()['s'];

$pendingBets = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('bet') . " WHERE status = 0")->fetch()['cnt'];

// 最新 10 条下注
$stmt = $pdo->query("SELECT b.*, u.nickname FROM " . table('bet') . " b LEFT JOIN " . table('user') . " u ON b.uid = u.uid ORDER BY b.created_at DESC LIMIT 10");
$recentBets = $stmt->fetchAll();

// 最新 10 条余额变动
$stmt = $pdo->query("SELECT * FROM " . table('balance_log') . " ORDER BY created_at DESC LIMIT 10");
$recentLogs = $stmt->fetchAll();

$statusMap = [0 => '待结算', 1 => '赢', 2 => '输'];
$actionMap = [
    'recharge' => '人工充值',
    'withdraw' => '人工提现',
    'bet' => '下注',
    'settle' => '结算',
    'rebate' => '返水',
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PC28 后台管理</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background: #f5f5f5; color: #333; }
        .header { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; padding: 20px 30px; }
        .header h1 { font-size: 24px; }
        .header .sub { opacity: 0.9; margin-top: 5px; font-size: 14px; }
        .nav { background: #fff; padding: 0 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); display: flex; gap: 0; }
        .nav a { padding: 14px 24px; text-decoration: none; color: #333; font-size: 14px; border-bottom: 2px solid transparent; }
        .nav a.active { color: #667eea; border-bottom-color: #667eea; }
        .nav a:hover { color: #667eea; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px 30px; }
        .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card .label { color: #888; font-size: 13px; }
        .card .value { font-size: 28px; font-weight: bold; margin-top: 8px; color: #333; }
        .card.primary { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
        .card.primary .label { color: rgba(255,255,255,0.9); }
        .card.primary .value { color: #fff; }
        .card.success .value { color: #28a745; }
        .card.warning .value { color: #ffc107; }
        .section { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); margin-bottom: 20px; }
        .section h2 { font-size: 18px; margin-bottom: 16px; padding-bottom: 10px; border-bottom: 1px solid #eee; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8f9fa; text-align: left; padding: 10px; font-size: 13px; color: #555; font-weight: 600; }
        td { padding: 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
        tr:hover { background: #fafafa; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
        .tag.green { background: #d4edda; color: #155724; }
        .tag.red { background: #f8d7da; color: #721c24; }
        .tag.gray { background: #e9ecef; color: #495057; }
        .tag.blue { background: #d1ecf1; color: #0c5460; }
        .tag.yellow { background: #fff3cd; color: #856404; }
        .toolbar { margin-bottom: 16px; display: flex; gap: 10px; align-items: center; }
        .toolbar input, .toolbar select { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
        .toolbar button { padding: 8px 16px; background: #667eea; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
        .toolbar button:hover { background: #5568d3; }
        .toolbar button.success { background: #28a745; }
        .toolbar button.danger { background: #dc3545; }
        .modal { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal.show { display: flex; }
        .modal-content { background: #fff; border-radius: 8px; padding: 24px; min-width: 400px; max-width: 600px; }
        .modal-content h3 { margin-bottom: 16px; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: #555; }
        .form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 16px; }
        .alert { padding: 12px 16px; border-radius: 4px; margin-bottom: 16px; display: none; }
        .alert.success { background: #d4edda; color: #155724; }
        .alert.error { background: #f8d7da; color: #721c24; }
        .alert.show { display: block; }
        .positive { color: #28a745; font-weight: 600; }
        .negative { color: #dc3545; font-weight: 600; }
    </style>
</head>
<body>
<div class="header">
    <h1>🎲 PC28 后台管理系统</h1>
    <div class="sub">用户管理 / 余额管理 / 下注管理</div>
</div>
<div class="nav">
    <a href="?page=index" class="<?= !isset($_GET['page']) || $_GET['page']==='index' ? 'active' : '' ?>">📊 控制台</a>
    <a href="?page=users" class="<?= ($_GET['page'] ?? '')==='users' ? 'active' : '' ?>">👥 用户管理</a>
    <a href="?page=balance" class="<?= ($_GET['page'] ?? '')==='balance' ? 'active' : '' ?>">💰 余额操作</a>
    <a href="?page=bets" class="<?= ($_GET['page'] ?? '')==='bets' ? 'active' : '' ?>">🎲 下注记录</a>
    <a href="?page=logs" class="<?= ($_GET['page'] ?? '')==='logs' ? 'active' : '' ?>">📋 余额流水</a>
    <a href="?page=botconfig" class="<?= ($_GET['page'] ?? '')==='botconfig' ? 'active' : '' ?>">⚙️ Bot配置</a>
</div>
<div class="container">
    <?php
    $page = $_GET['page'] ?? 'index';
    switch ($page) {
        case 'users': include __DIR__ . '/pages/users.php'; break;
        case 'balance': include __DIR__ . '/pages/balance.php'; break;
        case 'bets': include __DIR__ . '/pages/bets.php'; break;
        case 'logs': include __DIR__ . '/pages/logs.php'; break;
        case 'botconfig': include __DIR__ . '/pages/botconfig.php'; break;
        default: include __DIR__ . '/pages/dashboard.php'; break;
    }
    ?>
</div>
</body>
</html>
