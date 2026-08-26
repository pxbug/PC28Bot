<?php
$searchUid = trim($_GET['search_uid'] ?? '');
$searchIssue = trim($_GET['search_issue'] ?? '');
$page = max(1, intval($_GET['p'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];
if ($searchUid) { $where[] = "u.uid LIKE ?"; $params[] = '%' . $searchUid . '%'; }
if ($searchIssue) { $where[] = "b.issue LIKE ?"; $params[] = '%' . $searchIssue . '%'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = $pdo->query("SELECT COUNT(*) as cnt FROM " . table('bet') . " b LEFT JOIN " . table('user') . " u ON b.uid = u.uid $whereSql")->fetch()['cnt'];
$stmt = $pdo->prepare("SELECT b.*, u.nickname FROM " . table('bet') . " b LEFT JOIN " . table('user') . " u ON b.uid = u.uid $whereSql ORDER BY b.created_at DESC LIMIT $offset, $limit");
$stmt->execute($params);
$bets = $stmt->fetchAll();

$totalPages = ceil($total / $limit);
$statusMap = [0 => '待结算', 1 => '赢', 2 => '输'];
?>
<style>
    .toolbar { display: flex; gap: 10px; margin-bottom: 16px; align-items: center; flex-wrap: wrap; }
    .toolbar input { padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; }
    .toolbar button { padding: 8px 16px; background: #667eea; color: #fff; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 8px; overflow: hidden; }
    th { background: #f8f9fa; padding: 12px 10px; text-align: left; font-size: 13px; color: #555; }
    td { padding: 10px; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
    tr:hover { background: #fafafa; }
    .tag { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
    .tag.green { background: #d4edda; color: #155724; }
    .tag.red { background: #f8d7da; color: #721c24; }
    .tag.gray { background: #e9ecef; color: #495057; }
    .pagination { display: flex; gap: 4px; margin-top: 16px; }
    .pagination a, .pagination span { padding: 6px 12px; border: 1px solid #ddd; border-radius: 4px; font-size: 13px; color: #333; }
    .pagination a:hover { background: #f0f0f0; }
    .pagination .current { background: #667eea; color: #fff; border-color: #667eea; }
    .pagination .disabled { color: #ccc; pointer-events: none; }
</style>
<div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
    <h2 style="font-size:18px;margin-bottom:16px;">🎲 下注记录</h2>
    <div class="toolbar">
        <form method="GET" style="display:flex;gap:8px;">
            <input type="hidden" name="page" value="bets">
            <input type="text" name="search_uid" placeholder="用户UID" value="<?= htmlspecialchars($searchUid) ?>" style="width:150px;">
            <input type="text" name="search_issue" placeholder="期号" value="<?= htmlspecialchars($searchIssue) ?>" style="width:150px;">
            <button type="submit">🔍 搜索</button>
        </form>
        <span style="margin-left:auto;color:#888;font-size:13px;">共 <?= number_format($total) ?> 条记录</span>
    </div>
    <table>
        <thead>
            <tr>
                <th>用户</th>
                <th>期号</th>
                <th>玩法</th>
                <th>内容</th>
                <th>金额</th>
                <th>赔率</th>
                <th>结算金额</th>
                <th>状态</th>
                <th>下注时间</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bets as $b): ?>
            <tr>
                <td><?= htmlspecialchars($b['nickname'] ?: $b['uid']) ?></td>
                <td><?= htmlspecialchars($b['issue']) ?></td>
                <td><?= htmlspecialchars($b['bet_type']) ?></td>
                <td><?= htmlspecialchars($b['bet_content']) ?></td>
                <td><?= number_format($b['amount'], 2) ?></td>
                <td><?= $b['odds'] ?></td>
                <td><?= $b['settle_amount'] > 0 ? number_format($b['settle_amount'], 2) : '-' ?></td>
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
            <?php if (empty($bets)): ?>
            <tr><td colspan="9" style="text-align:center;color:#999;">暂无记录</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="pagination">
        <?php if ($page > 1): ?>
            <a href="?page=bets&search_uid=<?= urlencode($searchUid) ?>&search_issue=<?= urlencode($searchIssue) ?>&p=<?= $page - 1 ?>">上一页</a>
        <?php else: ?>
            <span class="disabled">上一页</span>
        <?php endif; ?>
        <span class="current"><?= $page ?>/<?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
            <a href="?page=bets&search_uid=<?= urlencode($searchUid) ?>&search_issue=<?= urlencode($searchIssue) ?>&p=<?= $page + 1 ?>">下一页</a>
        <?php else: ?>
            <span class="disabled">下一页</span>
        <?php endif; ?>
    </div>
</div>
