<?php
/**
 * Stats / Reports page
 */
require_once __DIR__ . '/src/db.php';
require_once __DIR__ . '/src/auth.php';

DB::init(__DIR__ . '/data/admin.db');
Auth::require();

$pageTitle = '数据统计';
$breadcrumb = [['数据统计', '/stats']];

$period = $_GET['period'] ?? 'today';
$now = time();

switch ($period) {
    case 'today':  $start = strtotime('today'); break;
    case 'yesterday': $start = strtotime('yesterday'); $end = strtotime('today'); break;
    case 'week':   $start = strtotime('monday this week'); break;
    case 'month':  $start = strtotime('first day of this month'); break;
    default:       $start = strtotime('today'); $period = 'today';
}
$end = $now;

// Aggregate stats
$totalBets = DB::count("SELECT COUNT(*) FROM bets WHERE created_at >= $start", []);
$totalBetAmount = DB::sum("SELECT SUM(amount) FROM bets WHERE created_at >= $start");
$totalWinAmount = DB::sum("SELECT SUM(win_amount) FROM bets WHERE created_at >= $start AND result='win'");
$totalLoseAmount = DB::sum("SELECT SUM(amount) FROM bets WHERE created_at >= $start AND result='lose'");
$platformProfit = $totalLoseAmount - $totalWinAmount;

$totalDeposits = DB::sum("SELECT SUM(amount) FROM deposits WHERE created_at >= $start AND status='approved'");
$totalWithdrawals = DB::sum("SELECT SUM(amount) FROM withdrawals WHERE created_at >= $start AND status='approved'");
$totalCashback = DB::sum("SELECT SUM(cashback) FROM cashback_log WHERE created_at >= $start");

$activeUsers = DB::count("SELECT COUNT(DISTINCT user_id) FROM bets WHERE created_at >= $start");
$newUsers = DB::count("SELECT COUNT(*) FROM users WHERE created_at >= $start");

// Bet type distribution
$betTypes = DB::fetchAll(
    "SELECT bet_type, COUNT(*) as cnt, SUM(amount) as total
     FROM bets WHERE created_at >= $start
     GROUP BY bet_type ORDER BY total DESC LIMIT 10"
);

// Daily trend (last 14 days)
$dailyData = [];
for ($i = 13; $i >= 0; $i--) {
    $dayStart = strtotime("-$i days midnight");
    $dayEnd = $dayStart + 86400;
    $betAmt = DB::sum("SELECT SUM(amount) FROM bets WHERE created_at >= $dayStart AND created_at < $dayEnd");
    $winAmt = DB::sum("SELECT SUM(win_amount) FROM bets WHERE created_at >= $dayStart AND created_at < $dayEnd AND result='win'");
    $loseAmt = DB::sum("SELECT SUM(amount) FROM bets WHERE created_at >= $dayStart AND created_at < $dayEnd AND result='lose'");
    $profit = $loseAmt - $winAmt;
    $dailyData[] = [
        'label' => date('m/d', $dayStart),
        'bets' => $betAmt,
        'profit' => $profit,
    ];
}

// Top bettors
$topBettors = DB::fetchAll(
    "SELECT u.id, u.nickname, SUM(b.amount) as total_bet, COUNT(*) as bet_count,
            SUM(b.win_amount) as total_win
     FROM bets b JOIN users u ON u.id=b.user_id
     WHERE b.created_at >= $start
     GROUP BY u.id ORDER BY total_bet DESC LIMIT 10"
);

// P&L by bet type
$betTypePL = DB::fetchAll(
    "SELECT bet_value, COUNT(*) as cnt, SUM(amount) as total_bet,
            SUM(CASE WHEN result='win' THEN win_amount ELSE 0 END) as total_win,
            SUM(CASE WHEN result='lose' THEN amount ELSE 0 END) as total_lose
     FROM bets WHERE created_at >= $start
     GROUP BY bet_value ORDER BY total_bet DESC LIMIT 15"
);
?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
    <div class="tabs" style="margin-bottom:0">
        <?php foreach (['today'=>'今日','yesterday'=>'昨日','week'=>'本周','month'=>'本月'] as $p=>$l): ?>
            <a href="?page=stats&period=<?= $p ?>" class="tab <?= $period===$p?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- P&L Summary -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">📊</span> 总下注额</div>
        <div class="kpi-value">¥<?= number_format($totalBetAmount, 2) ?></div>
        <div class="kpi-delta neutral"><?= number_format($totalBets) ?> 笔下注</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">💵</span> 平台盈亏</div>
        <div class="kpi-value <?= $platformProfit >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size:24px">
            <?= $platformProfit >= 0 ? '+' : '' ?>¥<?= number_format($platformProfit, 2) ?>
        </div>
        <div class="kpi-delta <?= $platformProfit >= 0 ? 'up' : 'down' ?>">
            <?= $platformProfit >= 0 ? '盈利' : '亏损' ?>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">💰</span> 充值总额</div>
        <div class="kpi-value text-success">¥<?= number_format($totalDeposits, 2) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">🏧</span> 提现总额</div>
        <div class="kpi-value text-danger">¥<?= number_format($totalWithdrawals, 2) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">💎</span> 返水总额</div>
        <div class="kpi-value">¥<?= number_format($totalCashback, 2) ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">👥</span> 活跃用户</div>
        <div class="kpi-value"><?= number_format($activeUsers) ?></div>
        <div class="kpi-delta neutral">新注册 <?= number_format($newUsers) ?> 人</div>
    </div>
</div>

<!-- Charts -->
<div class="chart-grid">
    <div class="chart-card">
        <div class="chart-title">近14天盈亏趋势</div>
        <canvas id="chart-profit" height="160"></canvas>
    </div>
    <div class="chart-card">
        <div class="chart-title">近14天下注金额</div>
        <canvas id="chart-bets" height="160"></canvas>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <!-- Top bettors -->
    <div class="data-table-wrap">
        <div class="table-header">
            <div class="table-title">投注排行榜 TOP 10</div>
        </div>
        <table class="data-table">
            <thead>
                <tr><th>#</th><th>用户</th><th class="text-right">投注额</th><th class="text-right">次数</th><th class="text-right">盈利</th></tr>
            </thead>
            <tbody>
                <?php foreach ($topBettors as $i => $b): $profit = $b['total_win'] - $b['total_bet']; ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><a href="?page=user_detail&id=<?= $b['id'] ?>"><?= htmlspecialchars($b['nickname']) ?></a></td>
                        <td class="text-right">¥<?= number_format($b['total_bet'], 2) ?></td>
                        <td class="text-right"><?= number_format($b['bet_count']) ?></td>
                        <td class="text-right <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $profit >= 0 ? '+' : '' ?>¥<?= number_format($profit, 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($topBettors)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:24px">暂无数据</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Bet type P&L -->
    <div class="data-table-wrap">
        <div class="table-header">
            <div class="table-title">玩法盈亏分析</div>
        </div>
        <table class="data-table">
            <thead>
                <tr><th>玩法</th><th class="text-right">投注额</th><th class="text-right">玩家赢</th><th class="text-right">平台盈亏</th></tr>
            </thead>
            <tbody>
                <?php foreach ($betTypePL as $b): $profit = $b['total_lose'] - $b['total_win']; ?>
                    <tr>
                        <td><?= htmlspecialchars($b['bet_value']) ?></td>
                        <td class="text-right">¥<?= number_format($b['total_bet'], 2) ?></td>
                        <td class="text-right text-success">¥<?= number_format($b['total_win'], 2) ?></td>
                        <td class="text-right <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>">
                            <?= $profit >= 0 ? '+' : '' ?>¥<?= number_format($profit, 2) ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($betTypePL)): ?>
                    <tr><td colspan="4" class="text-center text-muted" style="padding:24px">暂无数据</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const labels = [<?php foreach ($dailyData as $d) echo "'" . $d['label'] . "',"; ?>];
    const profits = [<?php foreach ($dailyData as $d) echo $d['profit'] . ','; ?>];
    const bets    = [<?php foreach ($dailyData as $d) echo $d['bets'] . ','; ?>];

    // P&L chart
    const ctxP = document.getElementById('chart-profit').getContext('2d');
    new Chart(ctxP, {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: '平台盈亏',
                data: profits,
                borderColor: '#34c759',
                backgroundColor: 'rgba(52,199,89,0.1)',
                fill: true, tension: 0.4,
                pointRadius: 3, pointBackgroundColor: '#34c759',
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    grid: { color: 'rgba(128,128,128,0.08)' },
                    ticks: { color: 'var(--text-tertiary)', callback: v => '¥' + (v>=0?'+':'')+v.toLocaleString() }
                },
                x: { grid: { display: false }, ticks: { color: 'var(--text-tertiary)' } }
            }
        }
    });

    // Bet amount chart
    const ctxB = document.getElementById('chart-bets').getContext('2d');
    new Chart(ctxB, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: '下注金额',
                data: bets,
                backgroundColor: 'rgba(0,113,227,0.15)',
                borderColor: 'rgba(0,113,227,0.7)',
                borderWidth: 1.5,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(128,128,128,0.08)' },
                    ticks: { color: 'var(--text-tertiary)', callback: v => '¥' + (v>=1000?(v/1000).toFixed(1)+'k':v) }
                },
                x: { grid: { display: false }, ticks: { color: 'var(--text-tertiary)' } }
            }
        }
    });
})();
</script>
