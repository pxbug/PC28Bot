<?php
/**
 * 反水记录
 */
use App\Db;
use App\Helper;

$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;
$uid    = trim($_GET['uid'] ?? '');
$period = trim($_GET['period'] ?? ''); // yyyy-mm-dd / yyyy-mm
$type   = $_GET['type'] ?? ''; // day/week/month

$where = []; $params = [];
if ($uid !== '')   { $where[] = 'r.uid LIKE :uid';   $params[':uid'] = "%{$uid}%"; }
if ($period !== '') { $where[] = 'r.period LIKE :period'; $params[':period'] = "%{$period}%"; }
if ($type !== '') {
    $p = match($type) {
        'day'   => date('Y-m-d'),
        'week'  => date('Y-W'),
        'month' => date('Y-m'),
        default => '',
    };
    if ($p) { $where[] = 'r.period LIKE :ptype'; $params[':ptype'] = "{$p}%"; }
}
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)(Db::fetch("SELECT COUNT(*) as c FROM bot_rebate_log r {$whereSQL}", $params)['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));
$rows = Db::fetchAll(
    "SELECT r.*, u.nickname
     FROM bot_rebate_log r LEFT JOIN bot_users u ON r.user_id = u.id
     {$whereSQL} ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);

// 汇总
$summary = Db::fetch(
    "SELECT COALESCE(SUM(r.rebate_amount),0) as total_amt, COUNT(*) as total_cnt,
            COALESCE(SUM(r.turnover),0) as total_turnover
     FROM bot_rebate_log r {$whereSQL}", $params
) ?? [];

$base = '/index.php?page=rebates' . ($uid?'&uid='.urlencode($uid):'') . ($period?"&period=".urlencode($period):'') . ($type?"&type={$type}":'');
?>

<!-- 汇总卡片 -->
<div class="flex gap-4 flex-wrap" style="margin-bottom:20px">
    <div class="stat-card" style="flex:1;min-width:150px">
        <div class="stat-label">累计反水总额</div>
        <div class="stat-value text-green"><?= Helper::money($summary['total_amt'] ?? 0) ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:150px">
        <div class="stat-label">累计返水笔数</div>
        <div class="stat-value"><?= number_format($summary['total_cnt'] ?? 0) ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:150px">
        <div class="stat-label">累计流水</div>
        <div class="stat-value"><?= Helper::money($summary['total_turnover'] ?? 0) ?></div>
    </div>
</div>

<form class="flex gap-3 items-center" style="margin-bottom:20px;flex-wrap:wrap">
    <input type="hidden" name="page" value="rebates">
    <input type="text" name="uid" class="input" placeholder="用户 UID" value="<?= Helper::h($uid) ?>" style="max-width:160px">
    <input type="text" name="period" class="input" placeholder="周期，如 2026-08" value="<?= Helper::h($period) ?>" style="max-width:140px">
    <select name="type" class="input" style="width:130px">
        <option value="">全部</option>
        <option value="day" <?= $type==='day'?'selected':'' ?>>今日</option>
        <option value="week" <?= $type==='week'?'selected':'' ?>>本周</option>
        <option value="month" <?= $type==='month'?'selected':'' ?>>本月</option>
    </select>
    <button type="submit" class="btn btn-primary">筛选</button>
    <?php if ($uid || $period || $type): ?><a href="/index.php?page=rebates" class="btn btn-secondary">重置</a><?php endif; ?>
    <span class="text-sm text-muted" style="margin-left:auto">共 <?= number_format($total) ?> 条</span>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>UID</th>
                <th>昵称</th>
                <th>周期</th>
                <th class="text-right">流水</th>
                <th class="text-right">投注期数</th>
                <th class="text-right">返点率</th>
                <th class="text-right">扣除率</th>
                <th class="text-right">反水金额</th>
                <th>备注</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="11" class="text-center text-muted" style="padding:40px">暂无数据</td></tr>
            <?php else: foreach ($rows as $row): ?>
            <tr>
                <td class="text-muted text-sm"><?= $row['id'] ?></td>
                <td><code style="font-size:12px"><?= Helper::h($row['uid']) ?></code></td>
                <td><?= Helper::h($row['nickname'] ?: '-') ?></td>
                <td><span class="badge badge-blue"><?= Helper::h($row['period']) ?></span></td>
                <td class="text-right"><?= Helper::money($row['turnover']) ?></td>
                <td class="text-right text-muted"><?= (int)$row['bet_count'] ?></td>
                <td class="text-right text-green"><?= number_format((float)$row['rebate_rate']*100, 2) ?>%</td>
                <td class="text-right text-red"><?= number_format((float)$row['deduct_rate']*100, 2) ?>%</td>
                <td class="text-right"><span class="text-green" style="font-weight:600">+<?= Helper::money($row['rebate_amount']) ?></span></td>
                <td class="text-muted text-sm"><?= Helper::h($row['remark']) ?: '-' ?></td>
                <td class="text-sm text-muted"><?= Helper::dt($row['created_at']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?= $page > 1 ? "<a href=\"{$base}&p=" . ($page-1) . "\">‹</a>" : '<span class="disabled">‹</span>' ?>
    <?php foreach (range(max(1,$page-2), min($totalPages,$page+2)) as $i): ?>
        <?= $i === $page ? "<span class=\"current\">{$i}</span>" : "<a href=\"{$base}&p={$i}\">{$i}</a>" ?>
    <?php endforeach; ?>
    <?= $page < $totalPages ? "<a href=\"{$base}&p=" . ($page+1) . "\">›</a>" : '<span class="disabled">›</span>' ?>
</div>
<?php endif; ?>