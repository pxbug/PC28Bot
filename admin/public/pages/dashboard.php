<?php
/**
 * Dashboard — KPI overview + mini charts
 */

$now = time();
$todayStart = strtotime('today');
$weekStart = strtotime('monday this week');
$monthStart = strtotime('first day of this month 00:00:00');

// KPI: Users
$totalUsers = DB::count("SELECT COUNT(*) FROM users");
$activeUsers = DB::count("SELECT COUNT(*) FROM users WHERE status = 'active'");
$newUsersToday = DB::count("SELECT COUNT(*) FROM users WHERE created_at >= $todayStart");

// KPI: Balance
$totalBalance = DB::sum("SELECT SUM(balance) FROM users");
$totalPlatformProfit = DB::sum("SELECT SUM(amount) FROM bets WHERE result = 'lose'") -
                       DB::sum("SELECT SUM(win_amount) FROM bets WHERE result = 'win'");

// KPI: Bets today
$betCountToday = DB::count("SELECT COUNT(*) FROM bets WHERE created_at >= $todayStart");
$betAmountToday = DB::sum("SELECT SUM(amount) FROM bets WHERE created_at >= $todayStart");

// KPI: Deposits & Withdrawals
$depositPending = DB::count("SELECT COUNT(*) FROM deposits WHERE status = 'pending'");
$withdrawPending = DB::count("SELECT COUNT(*) FROM withdrawals WHERE status = 'pending'");

// KPI: Platform profit today
$profitToday = DB::sum("SELECT SUM(amount) FROM bets WHERE created_at >= $todayStart AND result = 'lose'") -
               DB::sum("SELECT SUM(win_amount) FROM bets WHERE created_at >= $todayStart AND result = 'win'");

// Mini chart data: bet amount last 7 days
$dailyBetData = [];
for ($i = 6; $i >= 0; $i--) {
    $dayStart = strtotime("-$i days midnight");
    $dayEnd   = $dayStart + 86400;
    $amount = DB::sum("SELECT SUM(amount) FROM bets WHERE created_at >= $dayStart AND created_at < $dayEnd");
    $dailyBetData[] = ['label' => date('m/d', $dayStart), 'value' => $amount];
}

// Recent bets
$recentBets = DB::fetchAll(
    "SELECT b.id, b.period, b.bet_value, b.amount, b.result, b.win_amount, b.created_at,
            u.nickname, u.openid
     FROM bets b
     JOIN users u ON u.id = b.user_id
     ORDER BY b.id DESC LIMIT 8"
);

// Pending items
$pendingList = DB::fetchAll(
    "SELECT 'deposit' as type, d.id, d.amount, d.created_at, u.nickname, u.openid
     FROM deposits d JOIN users u ON u.id = d.user_id WHERE d.status = 'pending'
     UNION ALL
     SELECT 'withdraw' as type, w.id, w.amount, w.created_at, u.nickname, u.openid
     FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = 'pending'
     ORDER BY created_at DESC LIMIT 5"
);

// Lottery recent
$recentLottery = DB::fetchAll(
    "SELECT * FROM lottery_history ORDER BY id DESC LIMIT 5"
);

// Cashback today
$cashbackToday = DB::sum("SELECT SUM(cashback) FROM cashback_log WHERE created_at >= $todayStart");

$pageTitle = '仪表盘';
$pageScript = <<<JS
// Sparkline: daily bet amounts
(function(){
    const data = [{$dailyBetData[0]['value']},{$dailyBetData[1]['value']},{$dailyBetData[2]['value']},{$dailyBetData[3]['value']},{$dailyBetData[4]['value']},{$dailyBetData[5]['value']},{$dailyBetData[6]['value']}];
    const container = document.getElementById('sparkline-bets');
    if(container) renderSparkline(container, data, 'var(--accent)');
})();
JS;
?>
<style>
.kpi-card .kpi-label .icon { opacity: 0.7; }
.kpi-grid .kpi-card:nth-child(1) .kpi-label { color: #0071e3; }
.kpi-grid .kpi-card:nth-child(2) .kpi-label { color: #34c759; }
.kpi-grid .kpi-card:nth-child(3) .kpi-label { color: #ff9500; }
.kpi-grid .kpi-card:nth-child(4) .kpi-label { color: #af52de; }
.kpi-grid .kpi-card:nth-child(5) .kpi-label { color: #ff3b30; }
.kpi-grid .kpi-card:nth-child(6) .kpi-label { color: #5856d6; }
</style>

<!-- KPI Cards -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">👥</span> 总用户数</div>
        <div class="kpi-value"><?= number_format($totalUsers) ?></div>
        <div class="kpi-delta neutral">今日新注册 <?= $newUsersToday ?> 人</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">💰</span> 平台总余额</div>
        <div class="kpi-value">¥<?= number_format($totalBalance, 2) ?></div>
        <div class="kpi-delta <?= $profitToday >= 0 ? 'up' : 'down' ?>">
            今日盈亏 <?= $profitToday >= 0 ? '+' : '' ?>¥<?= number_format($profitToday, 2) ?>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">🎲</span> 今日下注</div>
        <div class="kpi-value"><?= number_format($betCountToday) ?></div>
        <div class="kpi-delta neutral">¥<?= number_format($betAmountToday, 2) ?></div>
        <div id="sparkline-bets" class="kpi-chart-mini"></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">🔔</span> 待处理充值</div>
        <div class="kpi-value"><?= $depositPending ?></div>
        <div class="kpi-delta <?= $depositPending > 0 ? 'up' : 'neutral' ?>">
            <?= $depositPending > 0 ? '需要处理' : '暂无待处理' ?>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">🏧</span> 待处理提现</div>
        <div class="kpi-value"><?= $withdrawPending ?></div>
        <div class="kpi-delta <?= $withdrawPending > 0 ? 'down' : 'neutral' ?>">
            <?= $withdrawPending > 0 ? '需要处理' : '暂无待处理' ?>
        </div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><span class="icon">💎</span> 今日返水</div>
        <div class="kpi-value">¥<?= number_format($cashbackToday, 2) ?></div>
        <div class="kpi-delta neutral">平台让利</div>
    </div>
</div>

<!-- Charts row -->
<div class="chart-grid">
    <!-- 7-day bet trend -->
    <div class="chart-card">
        <div class="chart-title">近7日下注金额趋势</div>
        <canvas id="chart-bets" height="140"></canvas>
    </div>
    <!-- Recent lottery results -->
    <div class="chart-card">
        <div class="chart-title">最近开奖结果</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:4px;">
            <?php foreach ($recentLottery as $lot): $nums = json_decode($lot['numbers'] ?? '[]', true); ?>
                <div style="text-align:center;background:rgba(128,128,128,0.06);border-radius:8px;padding:8px 10px;min-width:52px;">
                    <div style="font-size:10px;color:var(--text-tertiary);margin-bottom:4px;"><?= htmlspecialchars($lot['period']) ?></div>
                    <div style="display:flex;gap:3px;justify-content:center;">
                        <?php foreach ((array)$nums as $n): ?>
                            <span style="width:20px;height:20px;background:var(--accent-subtle);border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:11px;font-weight:600;color:var(--accent);"><?= $n ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div style="font-size:12px;font-weight:700;margin-top:4px;color:var(--text-primary);">= <?= $lot['total'] ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($recentLottery)): ?>
                <div class="empty-state" style="padding:20px">
                    <div class="empty-state-title">暂无开奖数据</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Two-column: Recent bets + Pending actions -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <!-- Recent bets -->
    <div class="data-table-wrap">
        <div class="table-header">
            <div class="table-title">最近下注</div>
            <a href="?page=bets" class="btn btn-secondary btn-sm">查看全部</a>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>用户</th>
                    <th>期号</th>
                    <th>下注</th>
                    <th>金额</th>
                    <th>结果</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentBets as $b): ?>
                    <tr>
                        <td>
                            <div style="font-weight:500;"><?= htmlspecialchars($b['nickname']) ?></div>
                            <div class="text-muted text-sm font-mono"><?= htmlspecialchars(substr($b['openid'],0,8)) ?></div>
                        </td>
                        <td class="font-mono"><?= htmlspecialchars($b['period']) ?></td>
                        <td><?= htmlspecialchars($b['bet_value']) ?></td>
                        <td>¥<?= number_format($b['amount'], 2) ?></td>
                        <td>
                            <?php if ($b['result'] === 'win'): ?>
                                <span class="badge badge-success">赢 ¥<?= number_format($b['win_amount'], 2) ?></span>
                            <?php elseif ($b['result'] === 'lose'): ?>
                                <span class="badge badge-danger">输</span>
                            <?php else: ?>
                                <span class="badge badge-neutral">等待</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($recentBets)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:32px">暂无下注记录</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pending actions -->
    <div class="data-table-wrap">
        <div class="table-header">
            <div class="table-title">待处理事项</div>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>类型</th>
                    <th>用户</th>
                    <th>金额</th>
                    <th>时间</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingList as $p): ?>
                    <tr>
                        <td>
                            <?php if ($p['type'] === 'deposit'): ?>
                                <span class="badge badge-success">充值</span>
                            <?php else: ?>
                                <span class="badge badge-warning">提现</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($p['nickname']) ?></td>
                        <td>¥<?= number_format($p['amount'], 2) ?></td>
                        <td class="text-sm text-muted"><?= timeAgo($p['created_at']) ?></td>
                        <td>
                            <a href="/<?= $p['type'] === 'deposit' ? 'deposits' : 'withdrawals' ?>" class="btn btn-sm btn-secondary">处理</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($pendingList)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:32px">暂无待处理事项</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    const labels = [<?php foreach ($dailyBetData as $d) echo "'" . $d['label'] . "',"; ?>];
    const values = [<?php foreach ($dailyBetData as $d) echo $d['value'] . ','; ?>];
    const ctx = document.getElementById('chart-bets').getContext('2d');
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: '下注金额',
                data: values,
                backgroundColor: 'rgba(0,113,227,0.15)',
                borderColor: 'rgba(0,113,227,0.8)',
                borderWidth: 1.5,
                borderRadius: 6,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(128,128,128,0.08)' },
                    ticks: { color: 'var(--text-tertiary)', callback: v => '¥' + (v >= 1000 ? (v/1000).toFixed(1)+'k' : v) }
                },
                x: {
                    grid: { display: false },
                    ticks: { color: 'var(--text-tertiary)' }
                }
            }
        }
    });
})();
</script>
