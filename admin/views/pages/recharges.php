<?php
/**
 * 充值记录
 */
use App\Db;
use App\Helper;

$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;
$uid    = trim($_GET['uid'] ?? '');
$date   = trim($_GET['date'] ?? '');

$where = []; $params = [];
if ($uid !== '')  { $where[] = 'r.user_id IN (SELECT id FROM bot_users WHERE uid LIKE :uid)'; $params[':uid'] = "%{$uid}%"; }
if ($date !== '') { $where[] = 'DATE(r.created_at) = :date'; $params[':date'] = $date; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)(Db::fetch("SELECT COUNT(*) as c FROM bot_recharges r {$whereSQL}", $params)['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));
$rows = Db::fetchAll(
    "SELECT r.*, u.uid as user_uid, u.nickname
     FROM bot_recharges r LEFT JOIN bot_users u ON r.user_id = u.id
     {$whereSQL} ORDER BY r.id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);

$totalAmount = (float)(Db::fetch(
    "SELECT COALESCE(SUM(r.amount),0) as s FROM bot_recharges r {$whereSQL}", $params
)['s'] ?? 0);

$base = '/index.php?page=recharges' . ($uid?'&uid='.urlencode($uid):'') . ($date?"&date={$date}":'');
?>

<form class="flex gap-3 items-center" style="margin-bottom:20px;flex-wrap:wrap">
    <input type="hidden" name="page" value="recharges">
    <input type="text" name="uid" class="input" placeholder="用户 UID" value="<?= Helper::h($uid) ?>" style="max-width:180px">
    <input type="date" name="date" class="input" value="<?= Helper::h($date) ?>" style="max-width:160px">
    <button type="submit" class="btn btn-primary">筛选</button>
    <?php if ($uid || $date): ?><a href="/index.php?page=recharges" class="btn btn-secondary">重置</a><?php endif; ?>
    <span class="text-sm" style="margin-left:auto">
        共 <?= number_format($total) ?> 条 &nbsp;|&nbsp;
        <strong class="text-green">总计 <?= Helper::money($totalAmount) ?> 元</strong>
    </span>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>用户 UID</th>
                <th>昵称</th>
                <th class="text-right">金额</th>
                <th>备注</th>
                <th>操作人</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-center text-muted" style="padding:40px">暂无数据</td></tr>
            <?php else: foreach ($rows as $row): ?>
            <tr>
                <td class="text-muted text-sm"><?= $row['id'] ?></td>
                <td><code style="font-size:12px"><?= Helper::h($row['user_uid'] ?? '-') ?></code></td>
                <td><?= Helper::h($row['nickname'] ?: '-') ?></td>
                <td class="text-right"><span class="text-green" style="font-weight:600">+<?= Helper::money($row['amount']) ?></span></td>
                <td class="text-muted text-sm"><?= Helper::h($row['remark']) ?: '-' ?></td>
                <td class="text-sm"><?= Helper::h($row['operator']) ?></td>
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