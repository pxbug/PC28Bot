<?php
/**
 * Bet records
 */

$pageTitle = '下注记录';
$breadcrumb = [['下注记录', '/bets']];

$search = trim($_GET['q'] ?? '');
$period = trim($_GET['period'] ?? '');
$status = $_GET['result'] ?? '';
$page = max(1, intval($_GET['p'] ?? 1));
$perPage = 25;

$where = [];
$args = [];
if ($search !== '') {
    $where[] = "(u.nickname LIKE ? OR u.openid LIKE ?)";
    $args[] = "%$search%";
    $args[] = "%$search%";
}
if ($period !== '') {
    $where[] = "b.period = ?";
    $args[] = $period;
}
if ($status !== '') {
    $where[] = "b.result = ?";
    $args[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = DB::count("SELECT COUNT(*) FROM bets b JOIN users u ON u.id=b.user_id $whereSql", $args);
$totalPages = max(1, ceil($total / $perPage));
$offset = ($page - 1) * $perPage;

$bets = DB::fetchAll(
    "SELECT b.*, u.nickname, u.openid FROM bets b JOIN users u ON u.id=b.user_id
     $whereSql ORDER BY b.id DESC LIMIT $perPage OFFSET $offset",
    $args
);

$totalAmount = DB::sum("SELECT SUM(b.amount) FROM bets b JOIN users u ON u.id=b.user_id $whereSql", $args);
$totalWin = DB::sum("SELECT SUM(b.win_amount) FROM bets b JOIN users u ON u.id=b.user_id $whereSql", $args);
?>
<div class="filter-bar">
    <form method="GET" action="?page=bets" style="display:flex;gap:8px;flex:1;flex-wrap:wrap;">
        <div class="search-bar" style="flex:1;min-width:200px;">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="搜索用户">
        </div>
        <input type="text" name="period" value="<?= htmlspecialchars($period) ?>" class="form-input" placeholder="期号" style="width:120px">
        <select name="result" class="form-input" style="width:auto">
            <option value="">全部结果</option>
            <option value="pending" <?= $status==='pending'?'selected':''?>>待结算</option>
            <option value="win" <?= $status==='win'?'selected':''?>>赢</option>
            <option value="lose" <?= $status==='lose'?'selected':''?>>输</option>
        </select>
        <button type="submit" class="btn btn-secondary">筛选</button>
    </form>
    <div style="font-size:13px;color:var(--text-secondary);white-space:nowrap;">
        共 <?= number_format($total) ?> 条 &nbsp;|&nbsp;
        总下注 ¥<?= number_format($totalAmount, 2) ?> &nbsp;|&nbsp;
        总中奖 ¥<?= number_format($totalWin, 2) ?>
    </div>
</div>

<div class="data-table-wrap">
    <table class="data-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>用户</th>
                <th>期号</th>
                <th>玩法</th>
                <th class="text-right">下注金额</th>
                <th class="text-right">赔率</th>
                <th>开奖结果</th>
                <th>状态</th>
                <th class="text-right">盈亏</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bets as $b): ?>
                <tr>
                    <td class="font-mono"><?= $b['id'] ?></td>
                    <td>
                        <a href="?page=user_detail&id=<?= $b['user_id'] ?>" style="font-weight:500;"><?= htmlspecialchars($b['nickname']) ?></a>
                    </td>
                    <td class="font-mono"><?= htmlspecialchars($b['period']) ?></td>
                    <td><?= htmlspecialchars($b['bet_value']) ?></td>
                    <td class="text-right">¥<?= number_format($b['amount'], 2) ?></td>
                    <td class="text-right"><?= $b['odds'] ?>x</td>
                    <td class="font-mono text-muted"><?= $b['lottery_result'] ? htmlspecialchars($b['lottery_result']) : '-' ?></td>
                    <td>
                        <?php if ($b['result'] === 'win'): ?>
                            <span class="badge badge-success">赢</span>
                        <?php elseif ($b['result'] === 'lose'): ?>
                            <span class="badge badge-danger">输</span>
                        <?php else: ?>
                            <span class="badge badge-neutral">待结算</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-right <?= $b['win_amount'] > 0 ? 'text-success' : ($b['win_amount'] < 0 ? 'text-danger' : '') ?>">
                        <?= $b['win_amount'] != 0 ? ($b['win_amount'] > 0 ? '+' : '') . '¥' . number_format($b['win_amount'], 2) : '-' ?>
                    </td>
                    <td class="text-sm text-muted"><?= timeAgo($b['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($bets)): ?>
                <tr><td colspan="10" class="text-center text-muted" style="padding:40px"><div class="empty-state-icon">🎲</div><div class="empty-state-title">暂无下注记录</div></td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if ($totalPages > 1): ?>
        <div class="pagination" id="pagination"></div>
        <script>renderPagination(document.getElementById('pagination'), <?= $page ?>, <?= $totalPages ?>, 'goPage'); function goPage(p){ const u=new URL(location); u.searchParams.set('p',p); location=u; }</script>
    <?php endif; ?>
</div>
