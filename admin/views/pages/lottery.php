<?php
/**
 * 开奖管理
 */
use App\Db;
use App\Helper;

$page   = max(1, (int)($_GET['p'] ?? 1));
$limit  = 30;
$offset = ($page - 1) * $limit;
$issue  = trim($_GET['issue'] ?? '');

$where = []; $params = [];
if ($issue !== '') { $where[] = 'issue LIKE :issue'; $params[':issue'] = "%{$issue}%"; }
$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = (int)(Db::fetch("SELECT COUNT(*) as c FROM bot_lottery {$whereSQL}", $params)['c'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));
$rows = Db::fetchAll(
    "SELECT * FROM bot_lottery {$whereSQL} ORDER BY id DESC LIMIT {$limit} OFFSET {$offset}",
    $params
);

// 统计
$totalIssues = (int)(Db::fetch('SELECT COUNT(*) as c FROM bot_lottery')['c'] ?? 0);
$settledIssues = (int)(Db::fetch('SELECT COUNT(*) as c FROM bot_lottery WHERE settled=1')['c'] ?? 0);

$base = '/index.php?page=lottery' . ($issue?'&issue='.urlencode($issue):'');
?>

<!-- 统计 -->
<div class="flex gap-4 flex-wrap" style="margin-bottom:20px">
    <div class="stat-card" style="flex:1;min-width:140px">
        <div class="stat-label">总开奖期数</div>
        <div class="stat-value"><?= number_format($totalIssues) ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:140px">
        <div class="stat-label">已结算</div>
        <div class="stat-value text-green"><?= number_format($settledIssues) ?></div>
    </div>
    <div class="stat-card" style="flex:1;min-width:140px">
        <div class="stat-label">待结算</div>
        <div class="stat-value text-orange"><?= number_format($totalIssues - $settledIssues) ?></div>
    </div>
</div>

<form class="flex gap-3 items-center" style="margin-bottom:20px;flex-wrap:wrap">
    <input type="hidden" name="page" value="lottery">
    <input type="text" name="issue" class="input" placeholder="期号" value="<?= Helper::h($issue) ?>" style="max-width:200px">
    <button type="submit" class="btn btn-primary">搜索</button>
    <?php if ($issue): ?><a href="/index.php?page=lottery" class="btn btn-secondary">重置</a><?php endif; ?>
    <span class="text-sm text-muted" style="margin-left:auto">共 <?= number_format($total) ?> 条</span>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>期号</th>
                <th>开奖号码</th>
                <th>和值</th>
                <th>大小</th>
                <th>单双</th>
                <th>状态</th>
                <th>时间</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="text-center text-muted" style="padding:40px">暂无数据</td></tr>
            <?php else: foreach ($rows as $row):
                $sum = (int)$row['sum'];
                $isDa = $sum >= 14;
                $isDan = $sum % 2 !== 0;
            ?>
            <tr>
                <td class="text-muted text-sm"><?= $row['id'] ?></td>
                <td><code style="font-size:12px"><?= Helper::h($row['issue']) ?></code></td>
                <td class="text-sm"><?= Helper::h($row['number']) ?></td>
                <td>
                    <span style="font-size:18px;font-weight:700;<?= $isDa ? 'color:var(--orange)' : 'color:var(--blue)' ?>">
                        <?= $sum ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= $isDa ? 'badge-orange' : 'badge-blue' ?>">
                        <?= $isDa ? '大' : '小' ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= $isDan ? 'badge-green' : 'badge-gray' ?>">
                        <?= $isDan ? '单' : '双' ?>
                    </span>
                </td>
                <td>
                    <span class="badge <?= $row['settled'] ? 'badge-green' : 'badge-orange' ?>">
                        <?= $row['settled'] ? '已结算' : '待结算' ?>
                    </span>
                </td>
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