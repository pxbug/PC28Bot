<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>控制台 - PC28 后台</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, "PingFang SC", "Microsoft YaHei", sans-serif; background: #f5f5f5; color: #333; }
        .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .card .label { color: #888; font-size: 13px; }
        .card .value { font-size: 28px; font-weight: bold; margin-top: 8px; }
        .card.primary { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; }
        .card.primary .label { color: rgba(255,255,255,0.9); }
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
        .positive { color: #28a745; font-weight: 600; }
        .negative { color: #dc3545; font-weight: 600; }
        a { color: #667eea; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
<?php
$totalUsers = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('user'))->fetch()['cnt'];
$totalBalance = $pdo->query("SELECT IFNULL(SUM(balance), 0) as s FROM " . table('user'))->fetch()['s'];
$todayBets = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('bet') . " WHERE DATE(created_at) = CURDATE()")->fetch()['cnt'];
$todayAmount = $pdo->query("SELECT IFNULL(SUM(amount), 0) as s FROM " . table('bet') . " WHERE DATE(created_at) = CURDATE()")->fetch()['s'];
$pendingBets = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('bet') . " WHERE status = 0")->fetch()['cnt'];
$stmt = $pdo->query("SELECT b.*, u.nickname FROM " . table('bet') . " b LEFT JOIN " . table('user') . " u ON b.uid = u.uid ORDER BY b.created_at DESC LIMIT 10");
$recentBets = $stmt->fetchAll();
$stmt = $pdo->query("SELECT * FROM " . table('balance_log') . " ORDER BY created_at DESC LIMIT 10");
$recentLogs = $stmt->fetchAll();
$statusMap = [0 => '待结算', 1 => '赢', 2 => '输'];
$actionMap = ['recharge' => '人工充值', 'withdraw' => '人工提现', 'bet' => '下注', 'settle' => '结算', 'rebate' => '返水'];
?>
<div class="cards">
    <div class="card primary">
        <div class="label">总用户数</div>
        <div class="value"><?= number_format($totalUsers) ?></div>
    </div>
    <div class="card">
        <div class="label">总余额</div>
        <div class="value">¥<?= number_format($totalBalance, 2) ?></div>
    </div>
    <div class="card">
        <div class="label">今日下注</div>
        <div class="value"><?= number_format($todayBets) ?> 注</div>
    </div>
    <div class="card">
        <div class="label">今日下注金额</div>
        <div class="value">¥<?= number_format($todayAmount, 2) ?></div>
    </div>
</div>
<div class="cards" style="grid-template-columns: repeat(3, 1fr);">
    <div class="card">
        <div class="label">待结算下注</div>
        <div class="value" style="color: #ffc107;"><?= number_format($pendingBets) ?></div>
    </div>
    <div class="card">
        <div class="label">今日充值笔数</div>
        <div class="value">
            <?php
            $rechargeCount = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('balance_log') . " WHERE action='recharge' AND DATE(created_at)=CURDATE()")->fetch()['cnt'];
            echo number_format($rechargeCount);
            ?>
        </div>
    </div>
    <div class="card">
        <div class="label">今日充值金额</div>
        <div class="value" style="color: #28a745;">
            ¥<?php
            $rechargeAmount = $pdo->query("SELECT IFNULL(SUM(amount), 0) as s FROM " . table('balance_log') . " WHERE action='recharge' AND DATE(created_at)=CURDATE()")->fetch()['s'];
            echo number_format($rechargeAmount, 2);
            ?>
        </div>
    </div>
</div>
<div class="section">
    <h2>📋 最新下注</h2>
    <table>
        <thead>
            <tr>
                <th>用户</th>
                <th>期号</th>
                <th>玩法</th>
                <th>内容</th>
                <th>金额</th>
                <th>赔率</th>
                <th>状态</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentBets as $b): ?>
            <tr>
                <td><?= htmlspecialchars($b['nickname'] ?: $b['uid']) ?></td>
                <td><?= htmlspecialchars($b['issue']) ?></td>
                <td><?= htmlspecialchars($b['bet_type']) ?></td>
                <td><?= htmlspecialchars($b['bet_content']) ?></td>
                <td><?= number_format($b['amount'], 2) ?></td>
                <td><?= $b['odds'] ?></td>
                <td>
                    <?php
                    $s = intval($b['status']);
                    $cls = $s===0?'gray':($s===1?'green':'red');
                    echo "<span class='tag $cls'>" . ($statusMap[$s] ?? '未知') . "</span>";
                    ?>
                </td>
                <td><?= substr($b['created_at'], 0, 16) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentBets)): ?>
            <tr><td colspan="8" style="text-align:center;color:#999;">暂无下注记录</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<div class="section">
    <h2>💰 最新余额变动</h2>
    <table>
        <thead>
            <tr>
                <th>用户</th>
                <th>类型</th>
                <th>金额</th>
                <th>变动前</th>
                <th>变动后</th>
                <th>备注</th>
                <th>期号</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recentLogs as $l): ?>
            <tr>
                <td><?= htmlspecialchars($l['uid']) ?></td>
                <td><?= htmlspecialchars($actionMap[$l['action']] ?? $l['action']) ?></td>
                <td class="<?= $l['amount']>=0?'positive':'negative' ?>"><?= $l['amount'] >= 0 ? '+' : '' ?><?= number_format($l['amount'], 2) ?></td>
                <td><?= number_format($l['balance_before'], 2) ?></td>
                <td><?= number_format($l['balance_after'], 2) ?></td>
                <td><?= htmlspecialchars($l['note']) ?></td>
                <td><?= htmlspecialchars($l['issue']) ?></td>
                <td><?= substr($l['created_at'], 0, 16) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recentLogs)): ?>
            <tr><td colspan="8" style="text-align:center;color:#999;">暂无流水记录</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
