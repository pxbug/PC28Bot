<?php
/**
 * 仪表盘
 */
use App\Db;
use App\Helper;

// 统计数据
$totalUsers = (int)(Db::fetch('SELECT COUNT(*) as c FROM bot_users')['c'] ?? 0);
$todayRecharge = (float)(Db::fetch(
    "SELECT COALESCE(SUM(amount),0) as s FROM bot_recharges WHERE DATE(created_at)=CURDATE()"
)['s'] ?? 0);
$todayWithdraw = (float)(Db::fetch(
    "SELECT COALESCE(SUM(amount),0) as s FROM bot_withdraws WHERE DATE(created_at)=CURDATE()"
)['s'] ?? 0);
$todayBet = (float)(Db::fetch(
    "SELECT COALESCE(SUM(amount),0) as s FROM bot_bets WHERE DATE(created_at)=CURDATE()"
)['s'] ?? 0);
$todayBetCount = (int)(Db::fetch(
    'SELECT COUNT(*) as c FROM bot_bets WHERE DATE(created_at)=CURDATE()'
)['c'] ?? 0);
$todayWinCount = (int)(Db::fetch(
    "SELECT COUNT(*) as c FROM bot_bets WHERE DATE(created_at)=CURDATE() AND status=1"
)['c'] ?? 0);
$todayPayout = (float)(Db::fetch(
    "SELECT COALESCE(SUM(payout),0) as s FROM bot_bets WHERE DATE(created_at)=CURDATE() AND status=1"
)['s'] ?? 0);
$activeUsers = (int)(Db::fetch(
    "SELECT COUNT(DISTINCT uid) as c FROM bot_bets WHERE DATE(created_at)=CURDATE()"
)['c'] ?? 0);

// 最近开奖
$recentLottery = Db::fetchAll(
    'SELECT * FROM bot_lottery ORDER BY id DESC LIMIT 8'
);

// 近7天每日下注趋势
$weekly = Db::fetchAll(
    "SELECT DATE(created_at) as d, COUNT(*) as cnt, SUM(amount) as amt
     FROM bot_bets
     WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
     GROUP BY d ORDER BY d ASC"
);
?>

<!-- 统计卡片 -->
<div class="flex gap-4 flex-wrap" style="margin-bottom:28px">
    <div class="stat-card" style="flex:1;min-width:180px">
        <div class="stat-label">总用户数</div>
        <div class="stat-value text-blue" id="stat-users"><?= number_format($totalUsers) ?></div>
        <div class="stat-sub">今日活跃 <?= $activeUsers ?> 人</div>
    </div>
    <div class="stat-card" style="flex:1;min-width:180px">
        <div class="stat-label">今日充值</div>
        <div class="stat-value text-green" id="stat-recharge"><?= Helper::money($todayRecharge) ?></div>
        <div class="stat-sub">元</div>
    </div>
    <div class="stat-card" style="flex:1;min-width:180px">
        <div class="stat-label">今日提现</div>
        <div class="stat-value text-red" id="stat-withdraw"><?= Helper::money($todayWithdraw) ?></div>
        <div class="stat-sub">元</div>
    </div>
    <div class="stat-card" style="flex:1;min-width:180px">
        <div class="stat-label">今日下注</div>
        <div class="stat-value" id="stat-bet"><?= Helper::money($todayBet) ?></div>
        <div class="stat-sub"><?= number_format($todayBetCount) ?> 注 / 中 <?= $todayWinCount ?> 注</div>
    </div>
    <div class="stat-card" style="flex:1;min-width:180px">
        <div class="stat-label">今日赔付</div>
        <div class="stat-value text-red" id="stat-payout"><?= Helper::money($todayPayout) ?></div>
        <div class="stat-sub">元</div>
    </div>
</div>

<div class="flex gap-4" style="flex-wrap:wrap">
    <!-- 最近开奖 -->
    <div class="card" style="flex:1;min-width:320px">
        <div class="card-header">最近开奖</div>
        <div class="table-wrap" style="box-shadow:none;border:none;border-radius:0">
            <table>
                <thead>
                    <tr>
                        <th>期号</th>
                        <th>开奖号码</th>
                        <th>和值</th>
                        <th>状态</th>
                        <th>时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentLottery)): ?>
                    <tr><td colspan="5" class="text-center text-muted" style="padding:32px">暂无数据</td></tr>
                    <?php else: foreach ($recentLottery as $row): ?>
                    <tr>
                        <td class="text-sm"><?= Helper::h($row['issue']) ?></td>
                        <td class="text-sm"><?= Helper::h($row['number']) ?></td>
                        <td>
                            <span class="badge <?= $row['sum'] >= 14 ? 'badge-orange' : 'badge-blue' ?>">
                                <?= (int)$row['sum'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge <?= $row['settled'] ? 'badge-green' : 'badge-gray' ?>">
                                <?= $row['settled'] ? '已结算' : '待结算' ?>
                            </span>
                        </td>
                        <td class="text-sm text-muted"><?= Helper::dt($row['created_at']) ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <div style="padding:12px 16px;border-top:.5px solid rgba(0,0,0,.06)">
            <a href="/index.php?page=lottery" class="btn btn-ghost btn-sm">查看全部 →</a>
        </div>
    </div>

    <!-- 近7天下注趋势 -->
    <div class="card" style="flex:1;min-width:280px;max-width:420px">
        <div class="card-header">近7天下注趋势</div>
        <div style="padding:20px">
            <?php if (empty($weekly)): ?>
            <div class="empty-state" style="padding:30px">
                <p>暂无数据</p>
            </div>
            <?php else: foreach ($weekly as $w): ?>
            <div class="flex items-center gap-3" style="margin-bottom:14px">
                <div class="text-sm text-muted" style="width:80px;flex-shrink:0"><?= $w['d'] ?></div>
                <div style="flex:1;height:24px;background:rgba(0,122,255,.08);border-radius:6px;overflow:hidden;position:relative">
                    <?php
                    $maxAmt = max(array_column($weekly, 'amt'));
                    $pct = $maxAmt > 0 ? ($w['amt'] / $maxAmt * 100) : 0;
                    ?>
                    <div style="height:100%;background:linear-gradient(90deg,var(--blue),var(--indigo));border-radius:6px;width:<?= $pct ?>%;transition:width .6s ease"></div>
                </div>
                <div class="text-sm" style="width:90px;text-align:right;font-weight:600">
                    <?= number_format((float)$w['amt'], 0) ?>
                    <span class="text-muted" style="font-weight:400"> / <?= (int)$w['cnt'] ?>注</span>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>