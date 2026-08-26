<?php
/**
 * 下注记录
 */
use App\Db;
use App\Helper;

$page    = max(1, (int)($_GET['p'] ?? 1));
$limit   = 25;
$offset  = ($page - 1) * $limit;
$uid     = trim($_GET['uid'] ?? '');
$issue   = trim($_GET['issue'] ?? '');
$status  = $_GET['status'] ?? '';

$where = []; $params = [];
if ($uid !== '')   { $where[] = 'b.uid LIKE :uid';   $params[':uid'] = "%{$uid}%"; }
if ($issue !== '') { $where[] = 'b.issue LIKE :issue'; $params[':issue'] = "%{$issue}%"; }
if ($status !== '') { $where[] = 'b.status = :status'; $params[':status'] = (int)$status; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)(Db::fetch(
    "SELECT COUNT(*) as c FROM bot_bets b {$whereSQL}", $params
)['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));

$rows = Db::fetchAll(
    "SELECT b.*, u.nickname, u.uid as user_uid
     FROM bot_bets b LEFT JOIN bot_users u ON b.user_id = u.id
     {$whereSQL} ORDER BY b.id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);

$pagerBase = '/index.php?page=bets' . ($uid?"&uid=".urlencode($uid):'') . ($issue?"&issue=".urlencode($issue):'') . ($status?"&status={$status}":'');
?>

<form class="flex gap-3 items-center" style="margin-bottom:20px;flex-wrap:wrap">
    <input type="hidden" name="page" value="bets">
    <input type="text" name="uid" class="input" placeholder="用户 UID" value="<?= Helper::h($uid) ?>" style="max-width:180px">
    <input type="text" name="issue" class="input" placeholder="期号" value="<?= Helper::h($issue) ?>" style="max-width:160px">
    <select name="status" class="input" style="width:120px">
        <option value="">全部状态</option>
        <option value="0" <?= $status==='0'?'selected':'' ?>>待开奖</option>
        <option value="1" <?= $status==='1'?'selected':'' ?>>已中奖</option>
        <option value="2" <?= $status==='2'?'selected':'' ?>>未中奖</option>
    </select>
    <button type="submit" class="btn btn-primary">
        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
        筛选
    </button>
    <?php if ($uid || $issue || $status): ?>
    <a href="/index.php?page=bets" class="btn btn-secondary">重置</a>
    <?php endif; ?>
    <span class="text-sm text-muted" style="margin-left:auto">共 <?= number_format($total) ?> 条</span>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>UID</th>
                <th>昵称</th>
                <th>期号</th>
                <th>玩法</th>
                <th>内容</th>
                <th class="text-right">金额</th>
                <th class="text-right">赔率</th>
                <th class="text-right">赔付</th>
                <th>状态</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="11" class="text-center text-muted" style="padding:40px">暂无数据</td></tr>
            <?php else: foreach ($rows as $row): ?>
            <tr>
                <td class="text-muted text-sm"><?= $row['id'] ?></td>
                <td><code style="font-size:12px"><?= Helper::h($row['user_uid'] ?? $row['uid']) ?></code></td>
                <td class="truncate" style="max-width:80px"><?= Helper::h($row['nickname'] ?? '-') ?></td>
                <td><code style="font-size:12px"><?= Helper::h($row['issue']) ?></code></td>
                <td><span class="badge badge-gray"><?= Helper::h($row['bet_type']) ?></span></td>
                <td><?= Helper::h($row['content']) ?></td>
                <td class="text-right font-weight:600"><?= Helper::money($row['amount']) ?></td>
                <td class="text-right text-muted"><?= number_format((float)$row['odds'], 2) ?></td>
                <td class="text-right <?= (float)$row['payout'] > 0 ? 'text-green' : 'text-muted' ?>" style="font-weight:600">
                    <?= (float)$row['payout'] > 0 ? '+' . Helper::money($row['payout']) : '-' ?>
                </td>
                <td>
                    <?php if ($row['status'] == 1): ?>
                        <span class="badge badge-green">已中奖</span>
                    <?php elseif ($row['status'] == 2): ?>
                        <span class="badge badge-red">未中奖</span>
                    <?php else: ?>
                        <span class="badge badge-gray">待开奖</span>
                    <?php endif; ?>
                </td>
                <td class="text-sm text-muted"><?= Helper::dt($row['created_at']) ?></td>
            </tr>
            <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?= $page > 1 ? "<a href=\"{$pagerBase}&p=" . ($page-1) . "\">‹</a>" : '<span class="disabled">‹</span>' ?>
    <?php
    foreach (range(max(1, $page-2), min($totalPages, $page+2)) as $i):
        echo $i === $page ? "<span class=\"current\">{$i}</span>" : "<a href=\"{$pagerBase}&p={$i}\">{$i}</a>";
    endforeach;
    ?>
    <?= $page < $totalPages ? "<a href=\"{$pagerBase}&p=" . ($page+1) . "\">›</a>" : '<span class="disabled">›</span>' ?>
</div>
<?php endif; ?>